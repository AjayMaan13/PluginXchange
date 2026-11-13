<?php

declare(strict_types=1);

namespace PluginXchange\Controllers;

use PluginXchange\Http\ApiException;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Request;
use PluginXchange\Repositories\OrderRepository;
use PluginXchange\Services\OrderService;

final class OrderController
{
    public function __construct(
        private readonly OrderService $service = new OrderService(),
        private readonly OrderRepository $orders = new OrderRepository()
    ) {
    }

    public function create(Request $request): JsonResponse
    {
        if ($request->resellerId === null) {
            throw new ApiException(403, 'FORBIDDEN', 'Orders must be placed with a reseller key');
        }

        $body = $request->json();

        $productId = $body['product_id'] ?? null;
        if (!is_int($productId) || $productId < 1) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'product_id must be a positive integer');
        }
        $quantity = $body['quantity'] ?? null;
        if (!is_int($quantity) || $quantity < 1 || $quantity > 100) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'quantity must be an integer between 1 and 100');
        }
        $idempotencyKey = $body['idempotency_key'] ?? null;
        $validKey = is_string($idempotencyKey) && $idempotencyKey !== '' && strlen($idempotencyKey) <= 64;
        if ($idempotencyKey !== null && !$validKey) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'idempotency_key must be a string of 1-64 characters');
        }

        [$order, $created] = $this->service->place(
            $request->resellerId,
            $productId,
            $quantity,
            $idempotencyKey,
            $request->requestId
        );

        return new JsonResponse($created ? 201 : 200, ['data' => $this->present($order)]);
    }

    public function show(Request $request): JsonResponse
    {
        $id = $request->params['id'] ?? '';
        $order = ctype_digit($id) ? $this->orders->find((int) $id) : null;
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Order not found');
        }
        if ($request->role !== 'admin' && $order['reseller_id'] !== $request->resellerId) {
            throw new ApiException(403, 'FORBIDDEN', 'You can only read your own orders');
        }

        return new JsonResponse(200, ['data' => $this->present($order)]);
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function present(array $order): array
    {
        unset($order['reseller_id']);
        return $order;
    }
}
