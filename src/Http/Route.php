<?php

declare(strict_types=1);

namespace PluginXchange\Http;

use Closure;

final class Route
{
    public const PUBLIC = 'public';       // no key needed
    public const RESELLER = 'reseller';   // any valid key (reseller or admin)
    public const ADMIN = 'admin';         // admin key only

    public readonly string $regex;

    public function __construct(
        public readonly string $method,
        string $pattern,
        public readonly Closure $handler,
        public readonly string $access
    ) {
        // "/products/{id}" becomes "#^/products/(?P<id>[^/]+)$#"
        $this->regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
    }
}
