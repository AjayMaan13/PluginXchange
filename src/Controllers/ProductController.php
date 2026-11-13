<?php

declare(strict_types=1);

namespace PluginXchange\Controllers;

use PluginXchange\Http\ApiException;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Pagination;
use PluginXchange\Http\Request;
use PluginXchange\Repositories\ProductRepository;

final class ProductController
{
    public function __construct(private readonly ProductRepository $products = new ProductRepository())
    {
    }

    public function index(Request $request): JsonResponse
    {
        $pagination = Pagination::fromQuery($request->query);

        $category = $request->query['category'] ?? null;
        $validCategory = is_string($category) && in_array($category, ProductRepository::CATEGORIES, true);
        if ($category !== null && !$validCategory) {
            throw new ApiException(
                422,
                'VALIDATION_FAILED',
                'category must be one of: ' . implode(', ', ProductRepository::CATEGORIES)
            );
        }

        $vendorId = null;
        if (isset($request->query['vendor_id'])) {
            $raw = $request->query['vendor_id'];
            $vendorId = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false;
            if ($vendorId === false || $vendorId < 1) {
                throw new ApiException(422, 'VALIDATION_FAILED', 'vendor_id must be a positive integer');
            }
        }

        [$rows, $total] = $this->products->list($category, $vendorId, $pagination->perPage, $pagination->offset());

        return new JsonResponse(200, [
            'data' => $rows,
            'meta' => ['page' => $pagination->page, 'per_page' => $pagination->perPage, 'total' => $total],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $id = $request->params['id'] ?? '';
        $product = ctype_digit($id) ? $this->products->find((int) $id) : null;
        if ($product === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Product not found');
        }

        return new JsonResponse(200, ['data' => $product]);
    }
}
