<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PluginXchange\Database;

// Temporary front controller; Phase 2 replaces this with a Router.
function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

match ($path) {
    '/' => respond(200, ['status' => 'ok']),
    '/health' => Database::ping()
        ? respond(200, ['status' => 'ok', 'db' => 'up'])
        : respond(503, ['status' => 'degraded', 'db' => 'down']),
    default => respond(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found']]),
};
