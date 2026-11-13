<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PluginXchange\Services\ImportValidator;

final class ImportValidatorTest extends TestCase
{
    private ImportValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ImportValidator();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function product(array $overrides = []): array
    {
        return $overrides + [
            'sku' => 'T-001',
            'name' => 'Test',
            'category' => 'synth',
            'price_cents' => 1000,
            'keys' => ['KEY-0001'],
        ];
    }

    /** @return list<string> the errors of a single row, [] when it is valid */
    private function errorsFor(mixed $row): array
    {
        $result = $this->validator->validate([$row]);
        return $result['rejected'][0]['errors'] ?? [];
    }

    private function assertRowRejected(mixed $row, string $messagePart): void
    {
        $errors = implode(' | ', $this->errorsFor($row));
        $this->assertStringContainsString($messagePart, $errors);
    }

    public function testFileNeedsVendorAndProducts(): void
    {
        $this->assertSame([], $this->validator->validateFile(['vendor' => 'V', 'products' => [[]]]));
        $this->assertCount(2, $this->validator->validateFile([]));
        $this->assertCount(1, $this->validator->validateFile(['vendor' => '  ', 'products' => [[]]]));
        $this->assertCount(1, $this->validator->validateFile(['vendor' => str_repeat('v', 121), 'products' => [[]]]));
        $this->assertCount(1, $this->validator->validateFile(['vendor' => 'V', 'products' => []]));
        $this->assertCount(1, $this->validator->validateFile(['vendor' => 'V', 'products' => ['a' => []]]));
    }

    public function testFileProductCountIsCapped(): void
    {
        $products = array_fill(0, ImportValidator::MAX_PRODUCTS + 1, []);
        $errors = $this->validator->validateFile(['vendor' => 'V', 'products' => $products]);

        $this->assertStringContainsString('limit is 1000', $errors[0]);
        $ok = array_fill(0, ImportValidator::MAX_PRODUCTS, []);
        $this->assertSame([], $this->validator->validateFile(['vendor' => 'V', 'products' => $ok]));
    }

    public function testValidRowIsNormalised(): void
    {
        $result = $this->validator->validate([$this->product(['name' => '  Spaced  ', 'keys' => []])]);

        $this->assertSame([], $result['rejected']);
        $this->assertSame('Spaced', $result['valid'][0]['name']);
        $this->assertSame([], $result['valid'][0]['keys']);

        // keys are optional
        $row = $this->product();
        unset($row['keys']);
        $this->assertSame([], $this->errorsFor($row));
    }

    public function testRowMustBeAnObject(): void
    {
        $this->assertRowRejected('text', 'product must be an object');
        $this->assertRowRejected([1, 2], 'product must be an object');
        $this->assertRowRejected(null, 'product must be an object');
    }

    public function testSkuRules(): void
    {
        $this->assertRowRejected($this->product(['sku' => '']), 'sku is required');
        $this->assertRowRejected($this->product(['sku' => 'has space']), 'sku is required');
        $this->assertRowRejected($this->product(['sku' => str_repeat('A', 65)]), 'sku is required');
        $this->assertRowRejected($this->product(['sku' => 123]), 'sku is required');
        $row = $this->product();
        unset($row['sku']);
        $this->assertRowRejected($row, 'sku is required');
    }

    public function testNameRules(): void
    {
        $this->assertRowRejected($this->product(['name' => '   ']), 'name is required');
        $this->assertRowRejected($this->product(['name' => str_repeat('n', 201)]), 'name is required');
        $this->assertRowRejected($this->product(['name' => ['x']]), 'name is required');
    }

    public function testCategoryMustBeInTheEnum(): void
    {
        $this->assertRowRejected($this->product(['category' => 'toaster']), 'category must be one of');
        $this->assertRowRejected($this->product(['category' => null]), 'category must be one of');
        foreach (['synth', 'effect', 'sample_pack', 'daw', 'other'] as $category) {
            $this->assertSame([], $this->errorsFor($this->product(['category' => $category])));
        }
    }

    public function testPriceMustBeAPositiveIntegerWithinTheLimit(): void
    {
        foreach ([0, -5, 1.5, '1000', null, ImportValidator::MAX_PRICE_CENTS + 1] as $price) {
            $this->assertRowRejected($this->product(['price_cents' => $price]), 'price_cents must be');
        }
        $this->assertSame([], $this->errorsFor($this->product(['price_cents' => 1])));
        $this->assertSame([], $this->errorsFor($this->product(['price_cents' => ImportValidator::MAX_PRICE_CENTS])));
    }

    public function testKeyFormatAndLength(): void
    {
        $this->assertRowRejected($this->product(['keys' => 'KEY-0001']), 'keys must be a list');
        $this->assertRowRejected($this->product(['keys' => ['abc']]), 'keys[0]');           // too short
        $this->assertRowRejected($this->product(['keys' => [str_repeat('K', 65)]]), 'keys[0]');   // too long
        $this->assertRowRejected($this->product(['keys' => ['KEY 0001']]), 'keys[0]');      // bad character
        $this->assertRowRejected($this->product(['keys' => ['-KEY0001']]), 'keys[0]');      // bad first character
        $this->assertRowRejected($this->product(['keys' => ['KEY-0001', 12345]]), 'keys[1]');
        $this->assertSame([], $this->errorsFor($this->product(['keys' => ['ABCD', str_repeat('K', 64)]])));
    }

    public function testKeyCountPerProductIsCapped(): void
    {
        $keys = array_map(fn($i) => "KEY-$i", range(1, ImportValidator::MAX_KEYS_PER_PRODUCT + 1));
        $this->assertRowRejected($this->product(['keys' => $keys]), 'limit is 5000');
    }

    public function testDuplicateKeysInsideAProductAreRejected(): void
    {
        $this->assertRowRejected($this->product(['keys' => ['KEY-0001', 'KEY-0001']]), 'duplicate key KEY-0001 within');
    }

    public function testDuplicateKeysAcrossRowsRejectTheLaterRow(): void
    {
        $result = $this->validator->validate([
            $this->product(['sku' => 'A-1', 'keys' => ['SHARED-1']]),
            $this->product(['sku' => 'B-1', 'keys' => ['OWN-0001', 'SHARED-1']]),
            $this->product(['sku' => 'C-1', 'keys' => ['OWN-0001']]),   // B-1 was rejected, so OWN-0001 is free
        ]);

        $this->assertSame([0, 2], array_keys($result['valid']));
        $this->assertCount(1, $result['rejected']);
        $this->assertSame(1, $result['rejected'][0]['index']);
        $this->assertSame('B-1', $result['rejected'][0]['sku']);
        $this->assertSame(['duplicate key SHARED-1 (already used at index 0)'], $result['rejected'][0]['errors']);
    }

    public function testDuplicateSkuRejectsTheLaterRow(): void
    {
        $result = $this->validator->validate([
            $this->product(['keys' => []]),
            $this->product(['keys' => []]),
        ]);

        $this->assertSame([0], array_keys($result['valid']));
        $this->assertStringContainsString('duplicate sku T-001', $result['rejected'][0]['errors'][0]);
    }

    public function testAllErrorsOfARowAreReportedTogether(): void
    {
        $errors = $this->errorsFor(['sku' => 'T-001', 'price_cents' => 0, 'category' => 'x', 'keys' => ['ab']]);

        $this->assertCount(4, $errors);   // name, category, price, key format
    }
}
