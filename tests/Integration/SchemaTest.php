<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Integration;

use PDOException;
use PHPUnit\Framework\TestCase;
use PluginXchange\Database;

final class SchemaTest extends TestCase
{
    public function testPingSucceeds(): void
    {
        $this->assertTrue(Database::ping());
    }

    public function testAllTablesExist(): void
    {
        $rows = Database::connection()
            ->query('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()')
            ->fetchAll();
        $tables = array_column($rows, 't');

        foreach (['vendors', 'products', 'license_keys', 'resellers', 'orders', 'audit_log'] as $expected) {
            $this->assertContains($expected, $tables);
        }
    }

    public function testDuplicateLicenseKeyIsRejected(): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec("INSERT INTO vendors (name) VALUES ('Schema Test Vendor')");
            $vendorId = (int) $pdo->lastInsertId();
            $pdo->exec(
                "INSERT INTO products (vendor_id, sku, name, category, price_cents)
                 VALUES ($vendorId, 'SCHEMA-TEST-1', 'T', 'other', 100)"
            );
            $productId = (int) $pdo->lastInsertId();
            $pdo->exec("INSERT INTO license_keys (product_id, key_value) VALUES ($productId, 'SCHEMA-DUP')");

            $this->expectException(PDOException::class);
            $pdo->exec("INSERT INTO license_keys (product_id, key_value) VALUES ($productId, 'SCHEMA-DUP')");
        } finally {
            $pdo->rollBack();
        }
    }
}
