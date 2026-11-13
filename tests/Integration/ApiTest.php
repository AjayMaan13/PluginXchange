<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PluginXchange\App;
use PluginXchange\Database;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Request;
use PluginXchange\Http\Route;
use RuntimeException;

/**
 * Drives the App directly (no web server). Each test runs inside a transaction that is rolled back,
 * and creates its own data, so it works on an empty or a seeded database.
 */
final class ApiTest extends TestCase
{
    private const ADMIN_KEY = 'test-admin-key';
    private const RESELLER_KEY = 'test-reseller-key';

    private PDO $pdo;
    private App $app;
    private int $vendorId;
    private int $synthId;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->beginTransaction();
        $this->app = new App(self::ADMIN_KEY);

        $this->pdo->exec("INSERT INTO vendors (name) VALUES ('ApiTest Vendor')");
        $this->vendorId = (int) $this->pdo->lastInsertId();
        $this->synthId = $this->insertProduct('APITEST-SYNTH', 'synth', 1);
        $this->insertProduct('APITEST-FX', 'effect', 1);
        $this->insertProduct('APITEST-OLD', 'synth', 0);

        // 3 available keys and 1 sold key on the synth
        for ($i = 1; $i <= 4; $i++) {
            $status = $i === 4 ? 'sold' : 'available';
            $this->pdo->exec(
                "INSERT INTO license_keys (product_id, key_value, status)
                 VALUES ($this->synthId, 'APITEST-KEY-$i', '$status')"
            );
        }

        $this->pdo->prepare('INSERT INTO resellers (name, country, api_key_hash) VALUES (?, ?, ?)')
            ->execute(['ApiTest Reseller', 'CA', hash('sha256', self::RESELLER_KEY)]);
    }

    protected function tearDown(): void
    {
        $this->pdo->rollBack();
    }

    private function insertProduct(string $sku, string $category, int $active): int
    {
        $this->pdo->exec(
            "INSERT INTO products (vendor_id, sku, name, category, price_cents, active)
             VALUES ($this->vendorId, '$sku', '$sku', '$category', 4999, $active)"
        );
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    private function call(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        string $body = ''
    ): JsonResponse {
        return $this->app->handle(new Request($method, $path, $query, $headers, $body));
    }

    /** @return array<string, string> */
    private function asReseller(): array
    {
        return ['X-API-Key' => self::RESELLER_KEY];
    }

    /** @return array<string, string> */
    private function asAdmin(): array
    {
        return ['X-API-Key' => self::ADMIN_KEY];
    }

    private function assertError(JsonResponse $r, int $status, string $code): void
    {
        $this->assertSame($status, $r->status);
        $this->assertSame($code, $r->body['error']['code']);
        $this->assertNotSame('', $r->body['error']['message']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $r->body['error']['request_id']);
        $this->assertSame($r->body['error']['request_id'], $r->headers['X-Request-Id']);
    }

    public function testPublicRoutesNeedNoKey(): void
    {
        $r = $this->call('GET', '/');
        $this->assertSame(200, $r->status);
        $this->assertSame(['status' => 'ok'], $r->body);

        $r = $this->call('GET', '/health');
        $this->assertSame(200, $r->status);
        $this->assertSame('up', $r->body['db']);
    }

    public function testMissingKeyIs401(): void
    {
        $this->assertError($this->call('GET', '/api/v1/products'), 401, 'UNAUTHORIZED');
    }

    public function testBadKeyIs401(): void
    {
        $r = $this->call('GET', '/api/v1/products', [], ['X-API-Key' => 'nope']);
        $this->assertError($r, 401, 'UNAUTHORIZED');
    }

    public function testListProductsWithValidKey(): void
    {
        $r = $this->call('GET', '/api/v1/products', ['vendor_id' => (string) $this->vendorId], $this->asReseller());

        $this->assertSame(200, $r->status);
        $this->assertCount(2, $r->body['data']);   // the inactive product is hidden
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 2], $r->body['meta']);
        $this->assertSame(4999, $r->body['data'][0]['price_cents']);
    }

    public function testFilterByCategory(): void
    {
        $r = $this->call(
            'GET',
            '/api/v1/products',
            ['vendor_id' => (string) $this->vendorId, 'category' => 'effect'],
            $this->asReseller()
        );

        $this->assertSame(1, $r->body['meta']['total']);
        $this->assertSame('APITEST-FX', $r->body['data'][0]['sku']);
    }

    public function testInvalidFiltersAre422(): void
    {
        $this->assertError(
            $this->call('GET', '/api/v1/products', ['category' => 'toaster'], $this->asReseller()),
            422,
            'VALIDATION_FAILED'
        );
        $this->assertError(
            $this->call('GET', '/api/v1/products', ['vendor_id' => 'abc'], $this->asReseller()),
            422,
            'VALIDATION_FAILED'
        );
    }

    public function testPagination(): void
    {
        $vendor = (string) $this->vendorId;

        $query = ['vendor_id' => $vendor, 'per_page' => '1', 'page' => '2'];
        $r = $this->call('GET', '/api/v1/products', $query, $this->asReseller());
        $this->assertCount(1, $r->body['data']);
        $this->assertSame(['page' => 2, 'per_page' => 1, 'total' => 2], $r->body['meta']);

        $r = $this->call('GET', '/api/v1/products', ['per_page' => '1000'], $this->asReseller());
        $this->assertSame(100, $r->body['meta']['per_page']);

        $r = $this->call('GET', '/api/v1/products', ['per_page' => '0'], $this->asReseller());
        $this->assertError($r, 422, 'VALIDATION_FAILED');
    }

    public function testHugePageNumberIs422NotAServerError(): void
    {
        $r = $this->call('GET', '/api/v1/products', ['page' => '9223372036854775807'], $this->asReseller());
        $this->assertError($r, 422, 'VALIDATION_FAILED');
        $this->assertStringContainsString('at most', $r->body['error']['message']);
    }

    public function testProductDetailIncludesAvailableKeys(): void
    {
        $r = $this->call('GET', "/api/v1/products/$this->synthId", [], $this->asReseller());

        $this->assertSame(200, $r->status);
        $this->assertSame('APITEST-SYNTH', $r->body['data']['sku']);
        $this->assertSame(3, $r->body['data']['available_keys']);
    }

    public function testUnknownProductIs404(): void
    {
        $this->assertError($this->call('GET', '/api/v1/products/999999999', [], $this->asReseller()), 404, 'NOT_FOUND');
        $this->assertError($this->call('GET', '/api/v1/products/abc', [], $this->asReseller()), 404, 'NOT_FOUND');
    }

    public function testUnknownRouteIs404AndWrongMethodIs405(): void
    {
        $this->assertError($this->call('GET', '/api/v1/nope', [], $this->asReseller()), 404, 'NOT_FOUND');
        $r = $this->call('DELETE', '/api/v1/products', [], $this->asReseller());
        $this->assertError($r, 405, 'METHOD_NOT_ALLOWED');
    }

    public function testCreateResellerRequiresAdmin(): void
    {
        $body = '{"name":"Shop","country":"US"}';

        $this->assertError($this->call('POST', '/api/v1/resellers', [], [], $body), 401, 'UNAUTHORIZED');
        $this->assertError($this->call('POST', '/api/v1/resellers', [], $this->asReseller(), $body), 403, 'FORBIDDEN');
    }

    public function testCreateResellerReturnsKeyOnceAndStoresOnlyHash(): void
    {
        $r = $this->call('POST', '/api/v1/resellers', [], $this->asAdmin(), '{"name":" New Shop ","country":"us"}');

        $this->assertSame(201, $r->status);
        $data = $r->body['data'];
        $this->assertSame('New Shop', $data['name']);
        $this->assertSame('US', $data['country']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['api_key']);

        $row = $this->pdo->query("SELECT * FROM resellers WHERE id = {$data['id']}")->fetch();
        $this->assertSame(hash('sha256', $data['api_key']), $row['api_key_hash']);
        $this->assertStringNotContainsString($data['api_key'], json_encode($row));

        $audit = $this->pdo->query(
            "SELECT request_id FROM audit_log WHERE entity_type = 'reseller' AND entity_id = {$data['id']}
             AND event = 'reseller.created'"
        )->fetchColumn();
        $this->assertSame($r->headers['X-Request-Id'], $audit);

        // The new key works right away
        $this->assertSame(
            200,
            $this->call('GET', '/api/v1/products', [], ['X-API-Key' => $data['api_key']])->status
        );
    }

    public function testCreateResellerValidationAndBadJson(): void
    {
        $this->assertError(
            $this->call('POST', '/api/v1/resellers', [], $this->asAdmin(), '{"name":"","country":"US"}'),
            422,
            'VALIDATION_FAILED'
        );
        $this->assertError(
            $this->call('POST', '/api/v1/resellers', [], $this->asAdmin(), '{"name":"Shop","country":"USA"}'),
            422,
            'VALIDATION_FAILED'
        );
        $this->assertError(
            $this->call('POST', '/api/v1/resellers', [], $this->asAdmin(), '{not json'),
            400,
            'BAD_JSON'
        );
    }

    public function testUnexpectedErrorsReturnGeneric500WithoutLeaking(): void
    {
        $this->app->router()->add(
            'GET',
            '/boom',
            fn() => throw new RuntimeException('secret db password'),
            Route::PUBLIC
        );
        $log = ini_set('error_log', '/dev/null');

        $r = $this->call('GET', '/boom');

        ini_set('error_log', (string) $log);
        $this->assertError($r, 500, 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('secret', json_encode($r->body));
    }
}
