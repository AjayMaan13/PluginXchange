<?php

declare(strict_types=1);

namespace PluginXchange\Services;

use PluginXchange\Database;
use PluginXchange\Repositories\AuditRepository;
use PluginXchange\Repositories\CatalogRepository;
use Throwable;

/**
 * @phpstan-import-type Product from ImportValidator
 */
final class ImportService
{
    public function __construct(
        private readonly ImportValidator $validator = new ImportValidator(),
        private readonly CatalogRepository $catalog = new CatalogRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    /**
     * Imports a vendor's catalog. Each product is its own transaction, so one failure never leaves half a
     * product behind and never blocks the others. Re-running the same file changes nothing.
     *
     * @param list<mixed> $products
     * @return array{
     *     products_created: int, products_updated: int, keys_added: int, rejected: list<array<string, mixed>>
     * }
     */
    public function import(string $vendorName, array $products, string $requestId): array
    {
        $checked = $this->validator->validate($products);
        $rejected = $checked['rejected'];
        $created = 0;
        $updated = 0;
        $keysAdded = 0;
        $vendorId = null;

        foreach ($checked['valid'] as $index => $product) {
            $vendorId ??= $this->catalog->ensureVendor(trim($vendorName));
            $result = $this->importProduct($vendorId, $product, $requestId);

            if ($result['errors'] !== []) {
                $rejected[] = ['index' => $index, 'sku' => $product['sku'], 'errors' => $result['errors']];
                continue;
            }
            $created += $result['created'] ? 1 : 0;
            $updated += $result['updated'] ? 1 : 0;
            $keysAdded += $result['keys_added'];
        }

        // Only log when something changed, so an identical re-run leaves the database untouched
        if ($vendorId !== null && ($created + $updated + $keysAdded) > 0) {
            $this->audit->log('vendor', $vendorId, 'catalog.imported', [
                'products_created' => $created,
                'products_updated' => $updated,
                'keys_added' => $keysAdded,
                'rejected' => count($rejected),
            ], $requestId);
        }

        usort($rejected, fn(array $a, array $b) => $a['index'] <=> $b['index']);

        return [
            'products_created' => $created,
            'products_updated' => $updated,
            'keys_added' => $keysAdded,
            'rejected' => $rejected,
        ];
    }

    /**
     * @param Product $product
     * @return array{errors: list<string>, created: bool, updated: bool, keys_added: int}
     */
    private function importProduct(int $vendorId, array $product, string $requestId): array
    {
        $none = ['errors' => [], 'created' => false, 'updated' => false, 'keys_added' => 0];
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $existing = $this->catalog->findProductBySkuForUpdate($product['sku']);
            if ($existing !== null && (int) $existing['vendor_id'] !== $vendorId) {
                $pdo->rollBack();
                return ['errors' => ["sku {$product['sku']} belongs to another vendor"]] + $none;
            }

            // Keys already in the database for this product are skipped; for another product they are an error
            $owners = $this->catalog->existingKeys($product['keys']);
            $productId = $existing === null ? null : (int) $existing['id'];
            $errors = [];
            $newKeys = [];
            foreach ($product['keys'] as $key) {
                if (!isset($owners[$key])) {
                    $newKeys[] = $key;
                } elseif ($owners[$key] !== $productId) {
                    $errors[] = "key $key already belongs to another product";
                }
            }
            if ($errors !== []) {
                $pdo->rollBack();
                return ['errors' => $errors] + $none;
            }

            $created = false;
            $updated = false;
            if ($existing === null) {
                $productId = $this->catalog->insertProduct(
                    $vendorId,
                    $product['sku'],
                    $product['name'],
                    $product['category'],
                    $product['price_cents']
                );
                $created = true;
            } elseif (
                $existing['name'] !== $product['name']
                || $existing['category'] !== $product['category']
                || (int) $existing['price_cents'] !== $product['price_cents']
            ) {
                $this->catalog->updateProduct(
                    (int) $existing['id'],
                    $product['name'],
                    $product['category'],
                    $product['price_cents']
                );
                $updated = true;
            }

            $this->catalog->insertKeys((int) $productId, $newKeys);
            $pdo->commit();

            return ['errors' => [], 'created' => $created, 'updated' => $updated, 'keys_added' => count($newKeys)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("[$requestId] import of {$product['sku']} failed: " . $e->getMessage());

            return ['errors' => ['could not be imported because of a database error']] + $none;
        }
    }
}
