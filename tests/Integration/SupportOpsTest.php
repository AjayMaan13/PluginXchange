<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Integration;

use PluginXchange\Http\JsonResponse;

final class SupportOpsTest extends OrderTestCase
{
    private function place(string $label, int $quantity, ?string $idempotencyKey = null): int
    {
        $body = ['product_id' => $this->productId, 'quantity' => $quantity];
        if ($idempotencyKey !== null) {
            $body['idempotency_key'] = $idempotencyKey;
        }
        $r = $this->call('POST', '/api/v1/orders', $label, [], $body);
        $this->assertSame(201, $r->status);

        return $r->body['data']['id'];
    }

    /** @return list<string> */
    private function eventNames(JsonResponse $trace): array
    {
        return array_column($trace->body['data']['events'], 'event');
    }

    public function testCancelRevokesKeysAndWritesAudit(): void
    {
        $id = $this->place('A', 3);

        $r = $this->call('POST', "/api/v1/orders/$id/cancel", 'admin');

        $this->assertSame(200, $r->status);
        $this->assertSame('cancelled', $r->body['data']['status']);
        $this->assertSame(3, $this->countKeys('revoked'));
        $this->assertSame(0, $this->countKeys('sold'));
        $this->assertSame(2, $this->countKeys('available'));   // a revoked key is never resold

        $rows = $this->pdo->query(
            "SELECT event, request_id FROM audit_log WHERE event IN ('order.cancelled', 'key.revoked')
             AND request_id = '{$r->headers['X-Request-Id']}'"
        )->fetchAll();
        $this->assertCount(4, $rows);   // 1 order.cancelled + 3 key.revoked, all with the request's id
    }

    public function testCancelTwiceIs409(): void
    {
        $id = $this->place('A', 2);
        $this->call('POST', "/api/v1/orders/$id/cancel", 'admin');

        $r = $this->call('POST', "/api/v1/orders/$id/cancel", 'admin');

        $this->assertSame(409, $r->status);
        $this->assertSame('ALREADY_CANCELLED', $r->body['error']['code']);
        $this->assertSame(2, $this->countKeys('revoked'));
    }

    public function testCancelRejectsFailedMissingAndNonAdmin(): void
    {
        $this->call('POST', '/api/v1/orders', 'A', [], ['product_id' => $this->productId, 'quantity' => 99]);
        $failedId = (int) $this->pdo->query(
            "SELECT id FROM orders WHERE product_id = $this->productId AND status = 'failed'"
        )->fetchColumn();
        // quantity 99 is valid input but there are only 5 keys, so this is a failed order
        $this->assertGreaterThan(0, $failedId);

        $r = $this->call('POST', "/api/v1/orders/$failedId/cancel", 'admin');
        $this->assertSame(409, $r->status);
        $this->assertSame('NOT_CANCELLABLE', $r->body['error']['code']);

        $this->assertSame(404, $this->call('POST', '/api/v1/orders/999999999/cancel', 'admin')->status);

        $id = $this->place('A', 1);
        $this->assertSame(403, $this->call('POST', "/api/v1/orders/$id/cancel", 'A')->status);
        $this->assertSame(1, $this->countKeys('sold'));
    }

    public function testHistoryIsPaginatedNewestFirstAndOwnOrdersOnly(): void
    {
        $first = $this->place('A', 1);
        $second = $this->place('A', 1);
        $third = $this->place('A', 1);
        $this->place('B', 1);

        $r = $this->call('GET', "/api/v1/resellers/$this->resellerA/orders", 'A', ['per_page' => '2']);
        $this->assertSame(200, $r->status);
        $this->assertSame([$third, $second], array_column($r->body['data'], 'id'));
        $this->assertSame(['page' => 1, 'per_page' => 2, 'total' => 3], $r->body['meta']);

        $r = $this->call('GET', "/api/v1/resellers/$this->resellerA/orders", 'A', ['per_page' => '2', 'page' => '2']);
        $this->assertSame([$first], array_column($r->body['data'], 'id'));
    }

    public function testHistoryAccessRules(): void
    {
        $this->place('A', 1);

        $this->assertSame(403, $this->call('GET', "/api/v1/resellers/$this->resellerA/orders", 'B')->status);
        $this->assertSame(200, $this->call('GET', "/api/v1/resellers/$this->resellerA/orders", 'admin')->status);
        $this->assertSame(404, $this->call('GET', '/api/v1/resellers/999999999/orders', 'admin')->status);
        $r = $this->call('GET', "/api/v1/resellers/$this->resellerA/orders", 'A', ['per_page' => '0']);
        $this->assertSame(422, $r->status);
    }

    public function testHistoryWithAHugePageNumberIs422(): void
    {
        $r = $this->call(
            'GET',
            "/api/v1/resellers/$this->resellerA/orders",
            'A',
            ['page' => '9223372036854775807']
        );

        $this->assertSame(422, $r->status);
    }

    public function testTraceExplainsAFailedOrder(): void
    {
        $r = $this->call('POST', '/api/v1/orders', 'A', [], ['product_id' => $this->productId, 'quantity' => 8]);
        $this->assertSame(409, $r->status);
        $failedId = (int) $this->pdo->query(
            "SELECT id FROM orders WHERE product_id = $this->productId AND status = 'failed'"
        )->fetchColumn();

        $trace = $this->call('GET', "/api/v1/support/orders/$failedId/trace", 'admin');

        $this->assertSame(200, $trace->status);
        $data = $trace->body['data'];
        $this->assertSame('failed', $data['order']['status']);
        $this->assertStringContainsString('Only 5', $data['order']['failure_reason']);
        $this->assertSame([], $data['keys']);
        $this->assertSame(['order.requested', 'order.failed'], $this->eventNames($trace));
        $this->assertSame(5, $data['events'][1]['detail']['available']);
        $this->assertSame(8, $data['events'][1]['detail']['requested']);
        $this->assertSame($r->headers['X-Request-Id'], $data['events'][1]['request_id']);
    }

    public function testTraceShowsTheFullLifeOfACancelledOrder(): void
    {
        $id = $this->place('A', 2);
        $this->call('POST', "/api/v1/orders/$id/cancel", 'admin');

        $trace = $this->call('GET', "/api/v1/support/orders/$id/trace", 'admin');

        $expected = [
            'order.requested', 'order.fulfilled', 'key.sold', 'key.sold',
            'order.cancelled', 'key.revoked', 'key.revoked',
        ];
        $this->assertSame($expected, $this->eventNames($trace));
        $this->assertSame('cancelled', $trace->body['data']['order']['status']);
        $this->assertSame(['revoked', 'revoked'], array_column($trace->body['data']['keys'], 'status'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $trace->body['data']['events'][0]['at']
        );
    }

    public function testTraceIsAdminOnlyAndMissingOrderIs404(): void
    {
        $id = $this->place('A', 1);

        $this->assertSame(403, $this->call('GET', "/api/v1/support/orders/$id/trace", 'A')->status);
        $this->assertSame(404, $this->call('GET', '/api/v1/support/orders/999999999/trace', 'admin')->status);
    }
}
