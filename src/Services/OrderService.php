<?php

declare(strict_types=1);

namespace PluginXchange\Services;

use Closure;
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
    /** Tries per order when keys exist but other in-flight orders hold locks on some of them. */
    private const MAX_ATTEMPTS = 5;

    /** @var Closure(): void */
    private readonly Closure $pause;

    /** @param (Closure(): void)|null $pause called between attempts; tests pass their own (default: 5-25 ms sleep) */
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
        ?Closure $pause = null
    ) {
        $this->pause = $pause ?? static function (): void {
            usleep(random_int(5000, 25000));
        };
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

        for ($attempt = 1;; $attempt++) {
            try {
                $orderId = $this->fulfil($resellerId, $productId, $quantity, $unitPrice, $idempotencyKey, $requestId);
                break;
            } catch (InsufficientStockException $e) {
                // SKIP LOCKED can come up short only because other in-flight orders hold locks on some keys, and
                // they may release them (see ADR 0002). If enough keys exist, wait a moment and try again.
                if ($attempt < self::MAX_ATTEMPTS && $this->orders->countAvailable($productId) >= $quantity) {
                    ($this->pause)();
                    continue;
                }
                $this->recordFailure($resellerId, $productId, $quantity, $unitPrice, $e, $requestId);
                throw new ApiException(409, 'INSUFFICIENT_STOCK', $e->getMessage());
            } catch (PDOException $e) {
                // Two identical requests raced past the check above: return the winner's order
                if ($idempotencyKey !== null && ($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_ENTRY) {
                    $existing = $this->orders->findByIdempotencyKey($resellerId, $idempotencyKey);
                    if ($existing !== null) {
                        return [$existing, false];
                    }
                }
                throw $e;
            }
        }

        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException("Order $orderId vanished after commit");
        }

        return [$order, true];
    }

    /**
     * One all-or-nothing attempt: lock the keys, write the order and its audit rows, commit.
     * Any failure rolls everything back and is rethrown.
     *
     * @throws InsufficientStockException when fewer than $quantity unlocked keys are available
     * @throws PDOException on any database error, for example a duplicate idempotency key
     */
    private function fulfil(
        int $resellerId,
        int $productId,
        int $quantity,
        int $unitPrice,
        ?string $idempotencyKey,
        string $requestId
    ): int {
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

            $this->audit->log('order', $orderId, 'order.requested', [
                'product_id' => $productId,
                'quantity' => $quantity,
                'idempotency_key' => $idempotencyKey,
            ], $requestId);
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

            return $orderId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancels a fulfilled order and revokes its keys, all or nothing.
     *
     * @return array<string, mixed> the updated order
     */
    public function cancel(int $orderId, string $requestId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $row = $this->orders->lockById($orderId);
            if ($row === null) {
                throw new ApiException(404, 'NOT_FOUND', 'Order not found');
            }
            if ($row['status'] === 'cancelled') {
                throw new ApiException(409, 'ALREADY_CANCELLED', 'Order is already cancelled');
            }
            if ($row['status'] !== 'fulfilled') {
                throw new ApiException(409, 'NOT_CANCELLABLE', 'Only fulfilled orders can be cancelled');
            }

            $keyIds = $this->orders->revokeKeys($orderId);
            $this->orders->setStatus($orderId, 'cancelled');

            $this->audit->log('order', $orderId, 'order.cancelled', ['revoked_keys' => count($keyIds)], $requestId);
            foreach ($keyIds as $keyId) {
                $this->audit->log('license_key', $keyId, 'key.revoked', ['order_id' => $orderId], $requestId);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException("Order $orderId vanished after commit");
        }

        return $order;
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
            $this->audit->log('order', $orderId, 'order.requested', [
                'product_id' => $productId,
                'quantity' => $quantity,
            ], $requestId);
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
