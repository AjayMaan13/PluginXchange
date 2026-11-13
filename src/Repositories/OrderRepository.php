<?php

declare(strict_types=1);

namespace PluginXchange\Repositories;

use PDO;
use PluginXchange\Database;

final class OrderRepository
{
    /**
     * Locks and returns up to $quantity available keys. Rows another transaction has locked are skipped,
     * so two concurrent orders can never get the same key (MySQL 8.0+, see ADR 0002).
     *
     * @return list<array{id: int|string, key_value: string}>
     */
    public function lockAvailableKeys(int $productId, int $quantity): array
    {
        // $quantity is an int validated by the controller, so it is safe to inline
        $stmt = Database::connection()->prepare(
            "SELECT id, key_value FROM license_keys
             WHERE product_id = :pid AND status = 'available'
             ORDER BY id LIMIT $quantity FOR UPDATE SKIP LOCKED"
        );
        $stmt->execute(['pid' => $productId]);
        /** @var list<array{id: int|string, key_value: string}> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    public function insert(
        int $resellerId,
        int $productId,
        int $quantity,
        int $unitPriceCents,
        string $status,
        ?string $failureReason,
        ?string $idempotencyKey
    ): int {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO orders (reseller_id, product_id, quantity, unit_price_cents, total_cents,
                                 status, failure_reason, idempotency_key)
             VALUES (:reseller, :product, :qty, :unit, :total, :status, :reason, :idem)'
        )->execute([
            'reseller' => $resellerId,
            'product' => $productId,
            'qty' => $quantity,
            'unit' => $unitPriceCents,
            'total' => $unitPriceCents * $quantity,
            'status' => $status,
            'reason' => $failureReason,
            'idem' => $idempotencyKey,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param list<int> $keyIds */
    public function markKeysSold(array $keyIds, int $orderId): void
    {
        $marks = implode(',', array_fill(0, count($keyIds), '?'));
        Database::connection()
            ->prepare("UPDATE license_keys SET status = 'sold', order_id = ? WHERE id IN ($marks)")
            ->execute([$orderId, ...$keyIds]);
    }

    /**
     * Locks the order row for the rest of the transaction.
     *
     * @return array{id: int|string, status: string}|null
     */
    public function lockById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, status FROM orders WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Sets the order's sold keys to revoked and returns their ids.
     *
     * @return list<int>
     */
    public function revokeKeys(int $orderId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id FROM license_keys WHERE order_id = :id AND status = 'sold' ORDER BY id");
        $stmt->execute(['id' => $orderId]);
        $ids = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        $pdo->prepare("UPDATE license_keys SET status = 'revoked' WHERE order_id = :id AND status = 'sold'")
            ->execute(['id' => $orderId]);

        return $ids;
    }

    public function setStatus(int $orderId, string $status): void
    {
        Database::connection()
            ->prepare('UPDATE orders SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $orderId]);
    }

    /** @return list<array{id: int, key_value: string, status: string}> */
    public function keysForOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, key_value, status FROM license_keys WHERE order_id = :id ORDER BY id'
        );
        $stmt->execute(['id' => $orderId]);

        return array_values(array_map(
            fn(array $row) => [
                'id' => (int) $row['id'],
                'key_value' => (string) $row['key_value'],
                'status' => (string) $row['status'],
            ],
            $stmt->fetchAll()
        ));
    }

    /**
     * Newest first, without keys.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} rows and total count
     */
    public function listForReseller(int $resellerId, int $limit, int $offset): array
    {
        $pdo = Database::connection();
        $count = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE reseller_id = :r');
        $count->execute(['r' => $resellerId]);

        // $limit and $offset are ints validated by Pagination, so they are safe to inline
        $stmt = $pdo->prepare(
            "SELECT id, status, product_id, quantity, unit_price_cents, total_cents, failure_reason, created_at
             FROM orders WHERE reseller_id = :r ORDER BY id DESC LIMIT $limit OFFSET $offset"
        );
        $stmt->execute(['r' => $resellerId]);

        $rows = array_values(array_map(self::formatListRow(...), $stmt->fetchAll()));

        return [$rows, (int) $count->fetchColumn()];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function formatListRow(array $row): array
    {
        foreach (['id', 'product_id', 'quantity', 'unit_price_cents', 'total_cents'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        return $row;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one('o.id = :v', $id);
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(int $resellerId, string $key): ?array
    {
        return $this->one('o.reseller_id = :r AND o.idempotency_key = :v', $key, $resellerId);
    }

    /** @return array<string, mixed>|null */
    private function one(string $where, int|string $value, ?int $resellerId = null): ?array
    {
        $params = ['v' => $value];
        if ($resellerId !== null) {
            $params['r'] = $resellerId;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT o.id, o.reseller_id, o.product_id, o.quantity, o.unit_price_cents, o.total_cents,
                    o.status, o.failure_reason, o.created_at
             FROM orders o WHERE $where"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $keys = $pdo->prepare('SELECT key_value FROM license_keys WHERE order_id = :id ORDER BY id');
        $keys->execute(['id' => $row['id']]);

        return [
            'id' => (int) $row['id'],
            'reseller_id' => (int) $row['reseller_id'],
            'status' => $row['status'],
            'product_id' => (int) $row['product_id'],
            'quantity' => (int) $row['quantity'],
            'unit_price_cents' => (int) $row['unit_price_cents'],
            'total_cents' => (int) $row['total_cents'],
            'keys' => $keys->fetchAll(PDO::FETCH_COLUMN),
            'failure_reason' => $row['failure_reason'],
            'created_at' => $row['created_at'],
        ];
    }
}
