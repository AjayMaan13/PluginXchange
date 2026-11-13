<?php

declare(strict_types=1);

namespace PluginXchange\Http;

final class Pagination
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    public function __construct(public readonly int $page, public readonly int $perPage)
    {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $page = self::positiveInt($query, 'page', 1);
        $perPage = min(self::positiveInt($query, 'per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);

        return new self($page, $perPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @param array<string, mixed> $query */
    private static function positiveInt(array $query, string $name, int $default): int
    {
        if (!isset($query[$name])) {
            return $default;
        }
        $value = is_string($query[$name]) ? filter_var($query[$name], FILTER_VALIDATE_INT) : false;
        if ($value === false || $value < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', "$name must be a positive integer");
        }
        return $value;
    }
}
