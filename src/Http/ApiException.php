<?php

declare(strict_types=1);

namespace PluginXchange\Http;

use RuntimeException;

/** An error the client should see, rendered in the standard error format. */
final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
