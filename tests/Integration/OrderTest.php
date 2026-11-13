<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Integration;

use PDO;
use PDOException;
use PluginXchange\Database;
use PluginXchange\Http\ApiException;
use PluginXchange\Services\OrderService;

final class OrderTest extends OrderTestCase
{
    public function testHappyPathAllocatesKeysAndWritesAudit(): void
    {
        $r = $this->order('A', ['product_id' => $this->productId, 'quantity' => 3]);

        $this->assertSame(201, $r->status);
        $data = $r->body['data'];
        $this->assertSame('fulfilled', $data['status']);
        $this->assertSame(14997, $data['total_cents']);
        $this->assertSame(4999, $data['unit_price_cents']);
        $this->assertCount(3, $data['keys']);
        $this->assertSame(3, $this->countKeys('sold'));
        $this->assertSame(2, $this->countKeys('available'));

        $orderId = (int) $data['id'];
        $this->assertSame(
            3,
            (int) $this->pdo->query("SELECT COUNT(*) FROM license_keys WHERE order_id = $orderId")->fetchColumn()
        );
        $audit = $this->pdo->query(
            "SELECT event, COUNT(*) AS n FROM audit_log
             WHERE (entity_type = 'order' AND entity_id = $orderId)
                OR (entity_type = 'license_key' AND event = 'key.sold'
                    AND entity_id IN (SELECT id FROM license_keys WHERE order_id = $orderId))
             GROUP BY event ORDER BY event"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(
            ['key.sold' => 3, 'order.fulfilled' => 1, 'order.requested' => 1],
            array_map('intval', $audit)
        );
    }

    public function testOutOfStockIs409AndRecordsFailedOrder(): void
    {
        $r = $this->order('A', ['product_id' => $this->productId, 'quantity' => 6]);

        $this->assertSame(409, $r->status);
        $this->assertSame('INSUFFICIENT_STOCK', $r->body['error']['code']);
        $this->assertSame(5, $this->countKeys('available'));

        $row = $this->pdo->query(
            "SELECT status, failure_reason, idempotency_key FROM orders WHERE product_id = $this->productId"
        )->fetch();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('Only 5', $row['failure_reason']);
        $this->assertNull($row['idempotency_key']);
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM audit_log a JOIN orders o ON a.entity_id = o.id
                 WHERE a.entity_type = 'order' AND a.event = 'order.failed' AND o.product_id = $this->productId"
            )->fetchColumn()
        );
    }

    public function testInvalidBodiesAre422(): void
    {
        $bad = [
            ['product_id' => $this->productId, 'quantity' => 0],
            ['product_id' => $this->productId, 'quantity' => -1],
            ['product_id' => $this->productId, 'quantity' => 101],
            ['product_id' => $this->productId, 'quantity' => '2'],
            ['product_id' => $this->productId],
            ['quantity' => 1],
            ['product_id' => $this->productId, 'quantity' => 1, 'idempotency_key' => ''],
        ];
        foreach ($bad as $body) {
            $r = $this->order('A', $body);
            $this->assertSame(422, $r->status, json_encode($body));
            $this->assertSame('VALIDATION_FAILED', $r->body['error']['code']);
        }
        $this->assertSame(5, $this->countKeys('available'));
    }

    public function testMissingAndInactiveProducts(): void
    {
        $r = $this->order('A', ['product_id' => 999999999, 'quantity' => 1]);
        $this->assertSame(404, $r->status);

        $r = $this->order('A', ['product_id' => $this->inactiveId, 'quantity' => 1]);
        $this->assertSame(422, $r->status);
    }

    public function testAdminKeyCannotPlaceOrders(): void
    {
        $r = $this->order('admin', ['product_id' => $this->productId, 'quantity' => 1]);
        $this->assertSame(403, $r->status);
    }

    public function testIdempotentRetryReturnsSameOrderWithoutSellingMore(): void
    {
        $body = ['product_id' => $this->productId, 'quantity' => 2, 'idempotency_key' => 'abc123'];

        $first = $this->order('A', $body);
        $second = $this->order('A', $body);

        $this->assertSame(201, $first->status);
        $this->assertSame(200, $second->status);
        $this->assertSame($first->body['data']['id'], $second->body['data']['id']);
        $this->assertSame($first->body['data']['keys'], $second->body['data']['keys']);
        $this->assertSame(2, $this->countKeys('sold'));

        // The same key from another reseller is a different order
        $other = $this->order('B', $body);
        $this->assertSame(201, $other->status);
        $this->assertNotSame($first->body['data']['id'], $other->body['data']['id']);
    }

    public function testIdempotencyKeyLengthCountsCharactersNotBytes(): void
    {
        $key = str_repeat('é', 64);
        $ok = $this->order('A', ['product_id' => $this->productId, 'quantity' => 1, 'idempotency_key' => $key]);
        $this->assertSame(201, $ok->status);

        $tooLong = $this->order(
            'A',
            ['product_id' => $this->productId, 'quantity' => 1, 'idempotency_key' => $key . 'é']
        );
        $this->assertSame(422, $tooLong->status);
        $this->assertSame(1, $this->countKeys('sold'));
    }

    /**
     * Another connection that locks the product's first $count keys, the way an in-flight order would.
     * The caller must roll it back (tearDown would otherwise wait on its locks).
     */
    private function holdLocksOnKeys(int $count): PDO
    {
        $other = new PDO(
            Database::dsn(),
            getenv('DB_USER') ?: 'pluginxchange',
            getenv('DB_PASS') ?: 'pluginxchange',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $other->beginTransaction();
        $other->query(
            "SELECT id FROM license_keys WHERE product_id = $this->productId AND status = 'available'
             ORDER BY id LIMIT $count FOR UPDATE"
        )->fetchAll();

        return $other;
    }

    public function testOrderRetriesWhenAnotherOrderHoldsLocksThatGetReleased(): void
    {
        // 5 keys, 3 locked by an in-flight order: an order for 3 sees only 2 unlocked keys
        $other = $this->holdLocksOnKeys(3);
        $pauses = 0;
        $service = new OrderService(pause: function () use ($other, &$pauses): void {
            $pauses++;
            $other->rollBack();   // the other order failed or finished, its locks are gone
        });

        try {
            [$order, $created] = $service->place($this->resellerA, $this->productId, 3, null, 'retry-test-1');
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }

        $this->assertTrue($created);
        $this->assertSame('fulfilled', $order['status']);
        $this->assertCount(3, $order['keys']);
        $this->assertSame(1, $pauses);
        $this->assertSame(3, $this->countKeys('sold'));
        $this->assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM orders WHERE product_id = $this->productId AND status = 'failed'"
            )->fetchColumn()
        );
    }

    public function testRealShortageFailsAtOnceWithoutRetrying(): void
    {
        $pauses = 0;
        $service = new OrderService(pause: function () use (&$pauses): void {
            $pauses++;
        });

        try {
            $service->place($this->resellerA, $this->productId, 8, null, 'retry-test-2');
            $this->fail('Expected INSUFFICIENT_STOCK');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('INSUFFICIENT_STOCK', $e->errorCode);
        }

        $this->assertSame(0, $pauses);   // only 5 keys exist, so waiting cannot help
        $this->assertSame(5, $this->countKeys('available'));
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM orders WHERE product_id = $this->productId AND status = 'failed'"
            )->fetchColumn()
        );
    }

    public function testRetriesAreBoundedWhenTheLocksNeverGoAway(): void
    {
        $other = $this->holdLocksOnKeys(3);
        $pauses = 0;
        $service = new OrderService(pause: function () use (&$pauses): void {
            $pauses++;
        });

        try {
            $service->place($this->resellerA, $this->productId, 3, null, 'retry-test-3');
            $this->fail('Expected INSUFFICIENT_STOCK');
        } catch (ApiException $e) {
            $this->assertSame('INSUFFICIENT_STOCK', $e->errorCode);
        } finally {
            $other->rollBack();
        }

        $this->assertSame(4, $pauses);   // 5 attempts, 4 pauses between them
        $this->assertSame(5, $this->countKeys('available'));
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM orders WHERE product_id = $this->productId AND status = 'failed'"
            )->fetchColumn()
        );
    }

    public function testReadingOrdersIsLimitedToTheOwner(): void
    {
        $id = (int) $this->order('A', ['product_id' => $this->productId, 'quantity' => 1])->body['data']['id'];

        $own = $this->getOrder('A', $id);
        $this->assertSame(200, $own->status);
        $this->assertCount(1, $own->body['data']['keys']);

        $this->assertSame(403, $this->getOrder('B', $id)->status);
        $this->assertSame(200, $this->getOrder('admin', $id)->status);
        $this->assertSame(404, $this->getOrder('A', 999999999)->status);

        // The order keeps the price it was bought at
        $this->pdo->exec("UPDATE products SET price_cents = 1 WHERE id = $this->productId");
        $this->assertSame(4999, $this->getOrder('A', $id)->body['data']['unit_price_cents']);
    }

    public function testErrorMidTransactionRollsEverythingBack(): void
    {
        // request_id is CHAR(16): this oversized id makes the audit insert fail
        // after the keys were already marked sold inside the transaction.
        $failed = false;
        try {
            (new OrderService())->place($this->resellerA, $this->productId, 3, 'rollback-1', str_repeat('x', 40));
        } catch (PDOException) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Expected the audit insert to fail');
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->countKeys('sold'));
        $this->assertSame(5, $this->countKeys('available'));
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM orders WHERE product_id = $this->productId")->fetchColumn()
        );
    }
}
