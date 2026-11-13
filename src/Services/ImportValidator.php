<?php

declare(strict_types=1);

namespace PluginXchange\Services;

use PluginXchange\Repositories\ProductRepository;

/**
 * Pure validation for catalog imports: no database, no I/O.
 *
 * @phpstan-type Product array{sku: string, name: string, category: string, price_cents: int, keys: list<string>}
 */
final class ImportValidator
{
    public const MAX_PRODUCTS = 1000;
    public const MAX_KEYS_PER_PRODUCT = 5000;
    public const MAX_PRICE_CENTS = 10000000;

    private const SKU_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{3,63}$/';   // 4-64 characters (column is VARCHAR(64))

    /**
     * Checks the top-level shape. Any error here means nothing is imported.
     *
     * @param array<string, mixed> $body
     * @return list<string>
     */
    public function validateFile(array $body): array
    {
        $errors = [];
        $vendor = $body['vendor'] ?? null;
        if (!is_string($vendor) || trim($vendor) === '' || mb_strlen(trim($vendor)) > 120) {
            $errors[] = 'vendor is required (max 120 characters)';
        }

        $products = $body['products'] ?? null;
        if (!is_array($products) || !array_is_list($products) || $products === []) {
            $errors[] = 'products must be a non-empty list';
        } elseif (count($products) > self::MAX_PRODUCTS) {
            $errors[] = 'products has ' . count($products) . ' entries, the limit is ' . self::MAX_PRODUCTS;
        }

        return $errors;
    }

    /**
     * Validates every row. Bad rows are rejected with reasons, good rows are returned normalised.
     * Duplicates inside the file are rejected on the later row.
     *
     * @param list<mixed> $products
     * @return array{
     *     valid: array<int, Product>,
     *     rejected: list<array{index: int, sku: string|null, errors: list<string>}>
     * }
     */
    public function validate(array $products): array
    {
        $valid = [];
        $rejected = [];
        $seenSkus = [];   // sku => index
        $seenKeys = [];   // key => index

        foreach ($products as $index => $row) {
            [$errors, $product] = $this->validateRow($row);

            if ($product !== null) {
                if (isset($seenSkus[$product['sku']])) {
                    $errors[] = "duplicate sku {$product['sku']} (already used at index {$seenSkus[$product['sku']]})";
                }
                foreach (array_keys(array_filter(array_count_values($product['keys']), fn($n) => $n > 1)) as $key) {
                    $errors[] = "duplicate key $key within the product";
                }
                foreach (array_unique($product['keys']) as $key) {
                    if (isset($seenKeys[$key])) {
                        $errors[] = "duplicate key $key (already used at index {$seenKeys[$key]})";
                    }
                }
            }

            if ($errors === [] && $product !== null) {
                $valid[$index] = $product;
                $seenSkus[$product['sku']] = $index;
                foreach ($product['keys'] as $key) {
                    $seenKeys[$key] = $index;
                }
                continue;
            }

            $sku = is_array($row) && is_string($row['sku'] ?? null) ? $row['sku'] : null;
            $rejected[] = ['index' => $index, 'sku' => $sku, 'errors' => $errors];
        }

        return ['valid' => $valid, 'rejected' => $rejected];
    }

    /**
     * @return array{0: list<string>, 1: Product|null}
     */
    private function validateRow(mixed $row): array
    {
        if (!is_array($row) || ($row !== [] && array_is_list($row))) {
            return [['product must be an object'], null];
        }

        $errors = [];

        $sku = $row['sku'] ?? null;
        if (!is_string($sku) || !preg_match(self::SKU_PATTERN, $sku)) {
            $errors[] = 'sku is required (1-64 letters, digits, . _ -)';
        }

        $name = $row['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 200) {
            $errors[] = 'name is required (max 200 characters)';
        }

        $category = $row['category'] ?? null;
        if (!is_string($category) || !in_array($category, ProductRepository::CATEGORIES, true)) {
            $errors[] = 'category must be one of: ' . implode(', ', ProductRepository::CATEGORIES);
        }

        $price = $row['price_cents'] ?? null;
        if (!is_int($price) || $price < 1 || $price > self::MAX_PRICE_CENTS) {
            $errors[] = 'price_cents must be an integer between 1 and ' . self::MAX_PRICE_CENTS;
        }

        $keys = $row['keys'] ?? [];
        $keyList = [];
        if (!is_array($keys) || !array_is_list($keys)) {
            $errors[] = 'keys must be a list';
        } elseif (count($keys) > self::MAX_KEYS_PER_PRODUCT) {
            $errors[] = 'keys has ' . count($keys) . ' entries, the limit is ' . self::MAX_KEYS_PER_PRODUCT;
        } else {
            foreach ($keys as $i => $key) {
                if (!is_string($key) || !preg_match(self::KEY_PATTERN, $key)) {
                    $errors[] = "keys[$i] must be 4-64 letters, digits, _ or - (starting with a letter or digit)";
                } else {
                    $keyList[] = $key;
                }
            }
        }

        if ($errors !== []) {
            return [$errors, null];
        }

        return [[], [
            'sku' => $sku,
            'name' => trim($name),
            'category' => $category,
            'price_cents' => $price,
            'keys' => $keyList,
        ]];
    }
}
