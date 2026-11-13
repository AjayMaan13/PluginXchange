<?php

declare(strict_types=1);

namespace PluginXchange\Controllers;

use PluginXchange\Http\ApiException;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Request;
use PluginXchange\Repositories\AuditRepository;
use PluginXchange\Repositories\ResellerRepository;

final class ResellerController
{
    public function __construct(
        private readonly ResellerRepository $resellers = new ResellerRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    public function create(Request $request): JsonResponse
    {
        $body = $request->json();

        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 120) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'name is required (max 120 characters)');
        }
        $country = $body['country'] ?? null;
        if (!is_string($country) || !preg_match('/^[A-Za-z]{2}$/', $country)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'country must be a 2-letter code');
        }

        $name = trim($name);
        $country = strtoupper($country);
        $apiKey = bin2hex(random_bytes(32));   // shown once; only the hash is stored
        $id = $this->resellers->create($name, $country, hash('sha256', $apiKey));

        $this->audit->log('reseller', $id, 'reseller.created', ['country' => $country], $request->requestId);

        return new JsonResponse(201, ['data' => [
            'id' => $id,
            'name' => $name,
            'country' => $country,
            'api_key' => $apiKey,
        ]]);
    }
}
