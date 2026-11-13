<?php

declare(strict_types=1);

namespace PluginXchange\Domain;

use RuntimeException;

final class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly int $available, public readonly int $requested)
    {
        parent::__construct("Only $available license keys available, $requested requested");
    }
}
