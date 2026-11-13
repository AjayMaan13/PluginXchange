<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PluginXchange\App;
use PluginXchange\Database;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Request;

/**
 * Shared fixture for tests that place real orders. Orders use real transactions (and write failed orders
 * after a rollback), so these tests cannot run inside a rolled-back transaction. They create their own rows
 * (a vendor, an active and an inactive product, 5 keys, resellers A and B) and delete them afterwards.
 */
abstract class OrderTestCase extends TestCase
{
    protected const ADMIN_KEY = 'order-test-admin';

    protected PDO $pdo;
    protected App $app;
    protected int $vendorId;
    protected int $productId;
    protected int $inactiveId;
    protected int $resellerA;
    protected int $resellerB;
    protected string $run;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->app = new App(self::ADMIN_KEY);
        $this->run = bin2hex(random_bytes(4));

        $this->pdo->exec("INSERT INTO vendors (name) VALUES ('OrderTest $this->run')");
        $this->vendorId = (int) $this->pdo->lastInsertId();
        $this->productId = $this->insertProduct('synth', 1);
        $this->inactiveId = $this->insertProduct('effect', 0);

        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec(
                "INSERT INTO license_keys (product_id, key_value) VALUES ($this->productId, 'OT-$this->run-$i')"
            );
        }
        $this->resellerA = $this->insertReseller('A');
        $this->resellerB = $this->insertReseller('B');
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $products = "SELECT id FROM products WHERE vendor_id = $this->vendorId";
        $this->pdo->exec(
            "DELETE a FROM audit_log a JOIN orders o ON a.entity_type = 'order' AND a.entity_id = o.id
             WHERE o.product_id IN ($products)"
        );
        $this->pdo->exec(
            "DELETE a FROM audit_log a JOIN license_keys k ON a.entity_type = 'license_key' AND a.entity_id = k.id
             WHERE k.product_id IN ($products)"
        );
        $this->pdo->exec("DELETE FROM license_keys WHERE product_id IN ($products)");
        $this->pdo->exec("DELETE FROM orders WHERE product_id IN ($products)");
        $this->pdo->exec("DELETE FROM products WHERE vendor_id = $this->vendorId");
        $this->pdo->exec("DELETE FROM resellers WHERE id IN ($this->resellerA, $this->resellerB)");
        $this->pdo->exec("DELETE FROM vendors WHERE id = $this->vendorId");
    }

    protected function insertProduct(string $category, int $active): int
    {
        $this->pdo->exec(
            "INSERT INTO products (vendor_id, sku, name, category, price_cents, active)
             VALUES ($this->vendorId, 'OT-$this->run-$category', 'OT', '$category', 4999, $active)"
        );
        return (int) $this->pdo->lastInsertId();
    }

    protected function insertReseller(string $label): int
    {
        $this->pdo->prepare('INSERT INTO resellers (name, country, api_key_hash) VALUES (?, ?, ?)')
            ->execute(["OT $label", 'CA', hash('sha256', $this->key($label))]);
        return (int) $this->pdo->lastInsertId();
    }

    protected function key(string $label): string
    {
        return "order-test-$this->run-$label";
    }

    /** @param array<string, mixed> $body */
    protected function order(string $label, array $body): JsonResponse
    {
        return $this->app->handle(new Request(
            'POST',
            '/api/v1/orders',
            [],
            ['X-API-Key' => $label === 'admin' ? self::ADMIN_KEY : $this->key($label)],
            json_encode($body, JSON_THROW_ON_ERROR)
        ));
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    protected function call(
        string $method,
        string $path,
        string $label,
        array $query = [],
        ?array $body = null
    ): JsonResponse {
        return $this->app->handle(new Request(
            $method,
            $path,
            $query,
            ['X-API-Key' => $label === 'admin' ? self::ADMIN_KEY : $this->key($label)],
            $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR)
        ));
    }

    protected function getOrder(string $label, int $id): JsonResponse
    {
        return $this->app->handle(new Request(
            'GET',
            "/api/v1/orders/$id",
            [],
            ['X-API-Key' => $label === 'admin' ? self::ADMIN_KEY : $this->key($label)]
        ));
    }

    protected function countKeys(string $status): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM license_keys WHERE product_id = $this->productId AND status = '$status'")
            ->fetchColumn();
    }
}
