<?php

declare(strict_types=1);

namespace PluginXchange\Controllers;

use PluginXchange\Http\ApiException;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Request;
use PluginXchange\Repositories\AuditRepository;
use PluginXchange\Repositories\OrderRepository;

final class SupportController
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    /** Everything support needs to explain an order: the order, its keys and what happened, in order. */
    public function trace(Request $request): JsonResponse
    {
        $id = $request->params['id'] ?? '';
        $order = ctype_digit($id) ? $this->orders->find((int) $id) : null;
        if ($order === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Order not found');
        }

        $keys = $this->orders->keysForOrder($order['id']);
        unset($order['keys']);   // shown with their status below

        return new JsonResponse(200, ['data' => [
            'order' => $order,
            'keys' => $keys,
            'events' => $this->audit->forOrder($order['id']),
        ]]);
    }
}
