<?php

declare(strict_types=1);

namespace PluginXchange\Http;

final class JsonResponse
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public array $headers = []
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode($this->body, JSON_UNESCAPED_SLASHES);
    }
}
