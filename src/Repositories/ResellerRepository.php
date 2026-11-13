<?php

declare(strict_types=1);

namespace PluginXchange\Repositories;

use PluginXchange\Database;

final class ResellerRepository
{
    /** @return array<string, mixed>|null */
    public function findByKeyHash(string $hash): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, name, country FROM resellers WHERE api_key_hash = :hash'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $name, string $country, string $keyHash): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO resellers (name, country, api_key_hash) VALUES (:name, :country, :hash)')
            ->execute(['name' => $name, 'country' => $country, 'hash' => $keyHash]);

        return (int) $pdo->lastInsertId();
    }
}
