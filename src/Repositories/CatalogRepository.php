<?php

declare(strict_types=1);

namespace PluginXchange\Repositories;

use PDO;
use PluginXchange\Database;

final class CatalogRepository
{
    /** Finds the vendor by name or creates it (safe if two imports race). */
    public function ensureVendor(string $name): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT IGNORE INTO vendors (name) VALUES (:name)')->execute(['name' => $name]);
        $stmt = $pdo->prepare('SELECT id FROM vendors WHERE name = :name');
        $stmt->execute(['name' => $name]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Locks the row, so call it inside a transaction.
     *
     * @return array<string, mixed>|null
     */
    public function findProductBySkuForUpdate(string $sku): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, vendor_id, name, category, price_cents FROM products WHERE sku = :sku FOR UPDATE'
        );
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function insertProduct(int $vendorId, string $sku, string $name, string $category, int $priceCents): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO products (vendor_id, sku, name, category, price_cents)
             VALUES (:vendor, :sku, :name, :category, :price)'
        )->execute([
            'vendor' => $vendorId,
            'sku' => $sku,
            'name' => $name,
            'category' => $category,
            'price' => $priceCents,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateProduct(int $id, string $name, string $category, int $priceCents): void
    {
        Database::connection()->prepare(
            'UPDATE products SET name = :name, category = :category, price_cents = :price WHERE id = :id'
        )->execute(['name' => $name, 'category' => $category, 'price' => $priceCents, 'id' => $id]);
    }

    /**
     * Which of these keys already exist, and for which product.
     *
     * @param list<string> $keys
     * @return array<string, int> key => product id
     */
    public function existingKeys(array $keys): array
    {
        $owners = [];
        foreach (array_chunk($keys, 1000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connection()->prepare(
                "SELECT key_value, product_id FROM license_keys WHERE key_value IN ($marks)"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$key, $productId]) {
                $owners[(string) $key] = (int) $productId;
            }
        }

        return $owners;
    }

    /** @param list<string> $keys */
    public function insertKeys(int $productId, array $keys): void
    {
        foreach (array_chunk($keys, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '(?, ?)'));
            $params = [];
            foreach ($chunk as $key) {
                array_push($params, $productId, $key);
            }
            Database::connection()
                ->prepare("INSERT INTO license_keys (product_id, key_value) VALUES $marks")
                ->execute($params);
        }
    }
}
