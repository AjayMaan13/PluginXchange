<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Integration;

use PDO;
use PluginXchange\IntegrityChecks;

final class IntegrityChecksTest extends OrderTestCase
{
    public function testHealthyDatabaseAfterOrdersAndCancelHasNoViolations(): void
    {
        $this->order('A', ['product_id' => $this->productId, 'quantity' => 2, 'idempotency_key' => 'ok-1']);
        $this->order('A', ['product_id' => $this->productId, 'quantity' => 99]);   // fails: out of stock
        $cancelled = $this->order('A', ['product_id' => $this->productId, 'quantity' => 1]);
        $orderId = $cancelled->body['data']['id'];
        $this->call('POST', "/api/v1/orders/$orderId/cancel", 'admin');

        $this->assertSame([], (new IntegrityChecks())->run());
    }

    public function testEveryCheckCatchesItsCorruption(): void
    {
        $keys = $this->pdo->query("SELECT id FROM license_keys WHERE product_id = $this->productId ORDER BY id")
            ->fetchAll(PDO::FETCH_COLUMN);

        // 1. sold key pointing at an order that does not exist
        $this->pdo->exec("UPDATE license_keys SET status = 'sold', order_id = 999999999 WHERE id = $keys[0]");

        // 2 and 3. fulfilled order with no keys and a wrong total
        $bad = $this->insertOrder($this->productId, 'fulfilled', 1, 4999, 1);

        // 4. key still sold on a cancelled order
        $cancelled = $this->insertOrder($this->productId, 'cancelled', 1, 4999, 4999);
        $this->pdo->exec("UPDATE license_keys SET status = 'sold', order_id = $cancelled WHERE id = $keys[1]");

        // 5. available key that still references an order
        $this->pdo->exec("UPDATE license_keys SET order_id = $bad WHERE id = $keys[2]");

        // 6. key attached to an order for another product
        $other = $this->insertOrder($this->inactiveId, 'cancelled', 1, 4999, 4999);
        $this->pdo->exec("UPDATE license_keys SET status = 'revoked', order_id = $other WHERE id = $keys[3]");

        $found = array_unique(array_column((new IntegrityChecks())->run(), 'check_name'));
        sort($found);

        $this->assertSame([
            'available_key_with_order',
            'fulfilled_order_key_count_mismatch',
            'key_product_mismatch',
            'order_total_mismatch',
            'sold_key_on_unfulfilled_order',
            'sold_key_without_order',
        ], $found);
    }

    public function testQueriesAreParsedFromTheSqlFile(): void
    {
        $this->assertCount(6, (new IntegrityChecks())->queries());
    }

    private function insertOrder(int $productId, string $status, int $qty, int $unit, int $total): int
    {
        $this->pdo->prepare(
            'INSERT INTO orders (reseller_id, product_id, quantity, unit_price_cents, total_cents, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->resellerA, $productId, $qty, $unit, $total, $status]);

        return (int) $this->pdo->lastInsertId();
    }
}
