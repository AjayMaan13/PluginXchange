<?php

declare(strict_types=1);

// Runs database/integrity-checks.sql and exits 1 if any query returns a row.
// Usage: php bin/integrity.php   (or `make integrity`)

require __DIR__ . '/../vendor/autoload.php';

$checks = new PluginXchange\IntegrityChecks();
$violations = $checks->run();

foreach ($violations as $v) {
    echo "VIOLATION {$v['check_name']} id={$v['id']} {$v['detail']}\n";
}

$total = count($checks->queries());
if ($violations !== []) {
    echo 'Integrity FAILED: ' . count($violations) . " violation(s) across $total checks\n";
    exit(1);
}
echo "Integrity OK: $total checks, 0 rows\n";
