<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PluginXchange\App;
use PluginXchange\Database;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Request;
use PluginXchange\IntegrityChecks;

/**
 * Imports commit real rows (one transaction per product), so these tests clean up the vendors named
 * "Fixture ..." before and after each test.
 */
final class ImportTest extends TestCase
{
    private const ADMIN_KEY = 'import-test-admin';

    private PDO $pdo;
    private App $app;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->app = new App(self::ADMIN_KEY);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $vendors = "SELECT id FROM vendors WHERE name LIKE 'Fixture %'";
        $products = "SELECT id FROM products WHERE vendor_id IN ($vendors)";
        $this->pdo->exec("DELETE FROM audit_log WHERE entity_type = 'vendor' AND entity_id IN ($vendors)");
        $this->pdo->exec("DELETE FROM license_keys WHERE product_id IN ($products)");
        $this->pdo->exec("DELETE FROM products WHERE vendor_id IN ($vendors)");
        $this->pdo->exec("DELETE FROM vendors WHERE name LIKE 'Fixture %'");
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/../fixtures/$name");
    }

    private function import(string $body, string $key = self::ADMIN_KEY): JsonResponse
    {
        return $this->app->handle(new Request('POST', '/api/v1/catalog/import', [], ['X-API-Key' => $key], $body));
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $vendors = "SELECT id FROM vendors WHERE name LIKE 'Fixture %'";
        $products = "SELECT id FROM products WHERE vendor_id IN ($vendors)";
        $count = fn(string $sql) => (int) $this->pdo->query($sql)->fetchColumn();

        return [
            'vendors' => $count("SELECT COUNT(*) FROM vendors WHERE name LIKE 'Fixture %'"),
            'products' => $count("SELECT COUNT(*) FROM products WHERE vendor_id IN ($vendors)"),
            'keys' => $count("SELECT COUNT(*) FROM license_keys WHERE product_id IN ($products)"),
            'audit' => $count(
                "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'vendor' AND entity_id IN ($vendors)"
            ),
            'checksum' => $count(
                "SELECT COALESCE(SUM(CRC32(CONCAT(sku, name, category, price_cents))), 0) FROM products
                 WHERE vendor_id IN ($vendors)"
            ),
        ];
    }

    public function testImportsANewVendorAndItsProducts(): void
    {
        $r = $this->import($this->fixture('valid.json'));

        $this->assertSame(200, $r->status);
        $this->assertSame(
            ['products_created' => 3, 'products_updated' => 0, 'keys_added' => 7, 'rejected' => []],
            $r->body['data']
        );
        $snapshot = $this->snapshot();
        $this->assertSame(1, $snapshot['vendors']);
        $this->assertSame(3, $snapshot['products']);
        $this->assertSame(7, $snapshot['keys']);
        $this->assertSame(1, $snapshot['audit']);   // catalog.imported
    }

    public function testRunningTheSameFileTwiceChangesNothing(): void
    {
        $this->import($this->fixture('valid.json'));
        $before = $this->snapshot();

        $r = $this->import($this->fixture('valid.json'));

        $this->assertSame(
            ['products_created' => 0, 'products_updated' => 0, 'keys_added' => 0, 'rejected' => []],
            $r->body['data']
        );
        $this->assertSame($before, $this->snapshot());
    }

    public function testIntegrityChecksPassAfterImports(): void
    {
        $this->import($this->fixture('valid.json'));
        $this->import($this->fixture('partial-bad.json'));
        $this->import($this->fixture('duplicate-keys.json'));

        $this->assertSame([], (new IntegrityChecks())->run());
    }

    public function testExistingProductsAreUpdatedAndKnownKeysSkipped(): void
    {
        $this->import($this->fixture('valid.json'));

        $changed = json_decode($this->fixture('valid.json'), true);
        $changed['products'][0]['price_cents'] = 5999;
        $changed['products'][0]['keys'][] = 'FIX-SYN-0004';   // one new key next to three known ones
        $r = $this->import(json_encode($changed));

        $this->assertSame(0, $r->body['data']['products_created']);
        $this->assertSame(1, $r->body['data']['products_updated']);
        $this->assertSame(1, $r->body['data']['keys_added']);
        $price = $this->pdo->query("SELECT price_cents FROM products WHERE sku = 'FIX-SYN-001'")->fetchColumn();
        $this->assertSame(5999, (int) $price);
        $this->assertSame(8, $this->snapshot()['keys']);
    }

    public function testBadRowsAreRejectedWithReasonsAndGoodRowsStillImport(): void
    {
        $r = $this->import($this->fixture('partial-bad.json'));

        $data = $r->body['data'];
        $this->assertSame(200, $r->status);
        $this->assertSame(2, $data['products_created']);
        $this->assertSame(3, $data['keys_added']);

        $byIndex = array_column($data['rejected'], null, 'index');
        $this->assertSame([1, 2, 3, 4, 6], array_keys($byIndex));
        $this->assertSame('FIX-FX-009', $byIndex[1]['sku']);
        $this->assertStringContainsString('price_cents', $byIndex[1]['errors'][0]);
        $this->assertStringContainsString('category', $byIndex[2]['errors'][0]);
        $this->assertCount(2, $byIndex[3]['errors']);   // missing name and a bad key
        $this->assertSame(['duplicate key PB-0002 (already used at index 0)'], $byIndex[4]['errors']);
        $this->assertNull($byIndex[6]['sku']);

        // Rejected rows left nothing behind
        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM products WHERE sku IN ('FIX-FX-009', 'FIX-FX-010', 'FIX-FX-011', 'FIX-FX-012')"
        )->fetchColumn());
    }

    public function testKeysThatBelongToAnotherProductRejectOnlyThatProduct(): void
    {
        $this->import($this->fixture('valid.json'));

        $r = $this->import($this->fixture('duplicate-keys.json'));

        $data = $r->body['data'];
        $this->assertSame(0, $data['products_created']);
        $this->assertSame(1, $data['products_updated']);   // price change on FIX-SYN-001
        $this->assertSame(1, $data['keys_added']);         // FIX-SYN-0004; FIX-SYN-0001 was already its own
        $this->assertCount(1, $data['rejected']);
        $this->assertSame('FIX-NEW-001', $data['rejected'][0]['sku']);
        $this->assertSame(['key FIX-FX-0001 already belongs to another product'], $data['rejected'][0]['errors']);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE sku = 'FIX-NEW-001'")->fetchColumn()
        );
    }

    public function testSkuOfAnotherVendorIsRejected(): void
    {
        $this->import($this->fixture('valid.json'));
        $body = json_decode($this->fixture('valid.json'), true);
        $body['vendor'] = 'Fixture Other';

        $r = $this->import(json_encode($body));

        $this->assertSame(0, $r->body['data']['products_created']);
        $this->assertCount(3, $r->body['data']['rejected']);
        $this->assertSame(['sku FIX-SYN-001 belongs to another vendor'], $r->body['data']['rejected'][0]['errors']);
    }

    public function testMalformedJsonIs400(): void
    {
        $r = $this->import($this->fixture('malformed.json'));

        $this->assertSame(400, $r->status);
        $this->assertSame('BAD_JSON', $r->body['error']['code']);
    }

    public function testOversizeFileIs413AndTooManyProductsIs422(): void
    {
        $r = $this->import('{"vendor":"Fixture Big","products":[],"pad":"' . str_repeat('x', 2000001) . '"}');
        $this->assertSame(413, $r->status);
        $this->assertSame('PAYLOAD_TOO_LARGE', $r->body['error']['code']);

        $products = array_fill(0, 1001, ['sku' => 'X-1']);
        $r = $this->import(json_encode(['vendor' => 'Fixture Many', 'products' => $products]));
        $this->assertSame(422, $r->status);
        $this->assertSame('VALIDATION_FAILED', $r->body['error']['code']);
        $this->assertSame(0, $this->snapshot()['vendors']);
    }

    public function testMissingVendorOrProductsIs422(): void
    {
        $this->assertSame(422, $this->import('{"products":[{"sku":"A"}]}')->status);
        $this->assertSame(422, $this->import('{"vendor":"Fixture X"}')->status);
        $this->assertSame(0, $this->snapshot()['vendors']);
    }

    public function testImportIsAdminOnly(): void
    {
        $this->assertSame(401, $this->app->handle(
            new Request('POST', '/api/v1/catalog/import', [], [], $this->fixture('valid.json'))
        )->status);

        $this->pdo->prepare('INSERT INTO resellers (name, country, api_key_hash) VALUES (?, ?, ?)')
            ->execute(['Import Test', 'CA', hash('sha256', 'import-test-reseller')]);
        $resellerId = (int) $this->pdo->lastInsertId();
        try {
            $r = $this->import($this->fixture('valid.json'), 'import-test-reseller');
            $this->assertSame(403, $r->status);
        } finally {
            $this->pdo->exec("DELETE FROM resellers WHERE id = $resellerId");
        }
        $this->assertSame(0, $this->snapshot()['products']);
    }

    public function testImportedProductsAreVisibleThroughTheCatalog(): void
    {
        $this->import($this->fixture('valid.json'));
        $id = (int) $this->pdo->query("SELECT id FROM products WHERE sku = 'FIX-SYN-001'")->fetchColumn();

        $r = $this->app->handle(
            new Request('GET', "/api/v1/products/$id", [], ['X-API-Key' => self::ADMIN_KEY])
        );

        $this->assertSame(200, $r->status);
        $this->assertSame(3, $r->body['data']['available_keys']);
    }
}
