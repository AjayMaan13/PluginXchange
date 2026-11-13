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
}
