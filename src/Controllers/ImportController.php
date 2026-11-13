<?php

declare(strict_types=1);

namespace PluginXchange\Controllers;

use PluginXchange\Http\ApiException;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Request;
use PluginXchange\Services\ImportService;
use PluginXchange\Services\ImportValidator;

final class ImportController
{
    public const MAX_BODY_BYTES = 2000000;

    public function __construct(
        private readonly ImportService $service = new ImportService(),
        private readonly ImportValidator $validator = new ImportValidator()
    ) {
    }

    public function import(Request $request): JsonResponse
    {
        if ($request->bodySize() > self::MAX_BODY_BYTES) {
            throw new ApiException(413, 'PAYLOAD_TOO_LARGE', 'Import file is larger than 2 MB');
        }

        $body = $request->json();
        $errors = $this->validator->validateFile($body);
        if ($errors !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', implode('; ', $errors));
        }

        $result = $this->service->import($body['vendor'], $body['products'], $request->requestId);

        return new JsonResponse(200, ['data' => $result]);
    }
}
