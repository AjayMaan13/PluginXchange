<?php

declare(strict_types=1);

namespace PluginXchange\Repositories;

use PluginXchange\Database;

final class AuditRepository
{
    /** @param array<string, mixed> $detail */
    public function log(string $entityType, int $entityId, string $event, array $detail, string $requestId): void
    {
        Database::connection()->prepare(
            'INSERT INTO audit_log (entity_type, entity_id, event, detail, request_id)
             VALUES (:type, :id, :event, :detail, :request_id)'
        )->execute([
            'type' => $entityType,
            'id' => $entityId,
            'event' => $event,
            'detail' => json_encode($detail, JSON_THROW_ON_ERROR),
            'request_id' => $requestId,
        ]);
    }

    /**
     * Every event for an order and for the license keys sold on it, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT created_at, event, entity_type, entity_id, detail, request_id FROM audit_log
             WHERE (entity_type = 'order' AND entity_id = :order_id)
                OR (entity_type = 'license_key'
                    AND entity_id IN (SELECT id FROM license_keys WHERE order_id = :key_order_id))
             ORDER BY created_at, id"
        );
        $stmt->execute(['order_id' => $orderId, 'key_order_id' => $orderId]);

        return array_map(fn(array $row) => [
            'at' => str_replace(' ', 'T', $row['created_at']) . 'Z',
            'event' => $row['event'],
            'entity_type' => $row['entity_type'],
            'entity_id' => (int) $row['entity_id'],
            'detail' => $row['detail'] === null ? null : json_decode($row['detail'], true),
            'request_id' => $row['request_id'],
        ], $stmt->fetchAll());
    }
}
