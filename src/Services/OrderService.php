<?php

declare(strict_types=1);

namespace PluginXchange\Services;

use PDOException;
use PluginXchange\Database;
use PluginXchange\Domain\InsufficientStockException;
use PluginXchange\Http\ApiException;
use PluginXchange\Repositories\AuditRepository;
use PluginXchange\Repositories\OrderRepository;
use PluginXchange\Repositories\ProductRepository;
use RuntimeException;
use Throwable;

final class OrderService
{
    private const MYSQL_DUPLICATE_ENTRY = 1062;

    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    /**
     * Places an order. All or nothing: either every key is sold and the order is fulfilled,
     * or nothing changes (and, for out-of-stock, a failed order is recorded).
     *
     * @return array{0: array<string, mixed>, 1: bool} the order and whether it was newly created
     */
    public function place(
        int $resellerId,
        int $productId,
        int $quantity,
        ?string $idempotencyKey,
        string $requestId
    ): array {
        if ($idempotencyKey !== null) {
            $existing = $this->orders->findByIdempotencyKey($resellerId, $idempotencyKey);
            if ($existing !== null) {
                return [$existing, false];
            }
        }

        $product = $this->products->find($productId);
        if ($product === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Product not found');
        }
        if (!$product['active']) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Product is not active');
        }
        $unitPrice = $product['price_cents'];

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $keys = $this->orders->lockAvailableKeys($productId, $quantity);
            if (count($keys) < $quantity) {
                throw new InsufficientStockException(count($keys), $quantity);
            }

            $orderId = $this->orders->insert(
                $resellerId,
                $productId,
                $quantity,
                $unitPrice,
                'fulfilled',
                null,
                $idempotencyKey
            );
            $keyIds = array_map(fn(array $key) => (int) $key['id'], $keys);
            $this->orders->markKeysSold($keyIds, $orderId);

            $this->audit->log('order', $orderId, 'order.fulfilled', [
                'reseller_id' => $resellerId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'total_cents' => $unitPrice * $quantity,
            ], $requestId);
            foreach ($keyIds as $keyId) {
                $this->audit->log('license_key', $keyId, 'key.sold', ['order_id' => $orderId], $requestId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof InsufficientStockException) {
                $this->recordFailure($resellerId, $productId, $quantity, $unitPrice, $e, $requestId);
                throw new ApiException(409, 'INSUFFICIENT_STOCK', $e->getMessage());
            }

            // Two identical requests raced past the check above: return the winner's order
            if (
                $idempotencyKey !== null
                && $e instanceof PDOException
                && ($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_ENTRY
            ) {
                $existing = $this->orders->findByIdempotencyKey($resellerId, $idempotencyKey);
                if ($existing !== null) {
                    return [$existing, false];
                }
            }
            throw $e;
        }

        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException("Order $orderId vanished after commit");
        }

        return [$order, true];
    }

    /** Runs after the main transaction rolled back, so the failure trace is not lost with it. */
    private function recordFailure(
        int $resellerId,
        int $productId,
        int $quantity,
        int $unitPrice,
        InsufficientStockException $e,
        string $requestId
    ): void {
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            // No idempotency key on failed orders, so the client can retry the same key after a restock
            $orderId = $this->orders->insert(
                $resellerId,
                $productId,
                $quantity,
                $unitPrice,
                'failed',
                $e->getMessage(),
                null
            );
            $this->audit->log('order', $orderId, 'order.failed', [
                'reseller_id' => $resellerId,
                'product_id' => $productId,
                'requested' => $e->requested,
                'available' => $e->available,
            ], $requestId);
            $pdo->commit();
        } catch (Throwable $inner) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("[$requestId] could not record failed order: " . $inner->getMessage());
        }
    }
}
