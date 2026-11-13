<?php

declare(strict_types=1);

namespace PluginXchange;

use RuntimeException;

/** Runs database/integrity-checks.sql. A healthy database returns no violations. */
final class IntegrityChecks
{
    public function __construct(private readonly string $sqlFile = __DIR__ . '/../database/integrity-checks.sql')
    {
    }

    /** @return list<array{check_name: string, id: int|string, detail: string}> */
    public function run(): array
    {
        $violations = [];
        foreach ($this->queries() as $query) {
            $stmt = Database::connection()->query($query);
            if ($stmt === false) {
                throw new RuntimeException("Integrity query failed: $query");
            }
            foreach ($stmt->fetchAll() as $row) {
                $violations[] = ['check_name' => $row['check_name'], 'id' => $row['id'], 'detail' => $row['detail']];
            }
        }

        return $violations;
    }

    /** @return list<string> */
    public function queries(): array
    {
        $sql = file_get_contents($this->sqlFile);
        if ($sql === false) {
            throw new RuntimeException("Cannot read {$this->sqlFile}");
        }

        // Drop comment lines, then split into statements
        $lines = array_filter(explode("\n", $sql), fn(string $line) => !str_starts_with(trim($line), '--'));

        return array_values(array_filter(array_map('trim', explode(';', implode("\n", $lines)))));
    }
}
