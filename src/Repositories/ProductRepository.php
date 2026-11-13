<?php

declare(strict_types=1);

namespace PluginXchange\Repositories;

use PluginXchange\Database;

final class ProductRepository
{
    public const CATEGORIES = ['synth', 'effect', 'sample_pack', 'daw', 'other'];

    /**
     * Active products only, newest id last.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} rows and total count
     */
    public function list(?string $category, ?int $vendorId, int $limit, int $offset): array
    {
        $where = ['active = 1'];
        $params = [];
        if ($category !== null) {
            $where[] = 'category = :category';
            $params['category'] = $category;
        }
        if ($vendorId !== null) {
            $where[] = 'vendor_id = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) FROM products WHERE $whereSql");
        $count->execute($params);

        // $limit and $offset are ints validated by Pagination, so they are safe to inline
        $stmt = $pdo->prepare(
            "SELECT id, vendor_id, sku, name, category, price_cents, active
             FROM products WHERE $whereSql ORDER BY id LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);

        $rows = array_values(array_map(fn(array $row) => self::format($row), $stmt->fetchAll()));

        return [$rows, (int) $count->fetchColumn()];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT id, vendor_id, sku, name, category, price_cents, active,
                    (SELECT COUNT(*) FROM license_keys k
                     WHERE k.product_id = products.id AND k.status = 'available') AS available_keys
             FROM products WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::format($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function format(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['vendor_id'] = (int) $row['vendor_id'];
        $row['price_cents'] = (int) $row['price_cents'];
        $row['active'] = (bool) $row['active'];
        if (isset($row['available_keys'])) {
            $row['available_keys'] = (int) $row['available_keys'];
        }
        return $row;
    }
}
