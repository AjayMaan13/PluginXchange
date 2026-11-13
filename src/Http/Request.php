<?php

declare(strict_types=1);

namespace PluginXchange\Http;

final class Request
{
    public readonly string $requestId;
    public string $role = 'anonymous';   // anonymous | reseller | admin
    public ?int $resellerId = null;
    /** @var array<string, string> */
    public array $params = [];
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        array $headers = [],
        private readonly string $body = ''
    ) {
        $this->requestId = bin2hex(random_bytes(8));
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }

        $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path === '' ? '/' : $path,
            $_GET,
            $headers,
            (string) file_get_contents('php://input')
        );
    }

    public function bodySize(): int
    {
        return strlen($this->body);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Parsed JSON object body. An empty body is an empty object.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if (trim($this->body) === '') {
            return [];
        }
        $data = json_decode($this->body, true);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ApiException(400, 'BAD_JSON', 'Request body must be a valid JSON object');
        }
        return $data;
    }
}
