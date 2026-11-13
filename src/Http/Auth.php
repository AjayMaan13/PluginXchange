<?php

declare(strict_types=1);

namespace PluginXchange\Http;

use PluginXchange\Repositories\ResellerRepository;

final class Auth
{
    public function __construct(
        private readonly ResellerRepository $resellers,
        private readonly string $adminKey
    ) {
    }

    /** Checks the X-API-Key header against the route's access level and sets the role on the request. */
    public function authenticate(Request $request, string $access): void
    {
        if ($access === Route::PUBLIC) {
            return;
        }

        $key = $request->header('X-API-Key');
        if ($key === null || $key === '') {
            throw new ApiException(401, 'UNAUTHORIZED', 'Missing X-API-Key header');
        }

        if ($this->adminKey !== '' && hash_equals($this->adminKey, $key)) {
            $request->role = 'admin';
            return;
        }

        $reseller = $this->resellers->findByKeyHash(hash('sha256', $key));
        if ($reseller === null) {
            throw new ApiException(401, 'UNAUTHORIZED', 'Invalid API key');
        }
        $request->role = 'reseller';
        $request->resellerId = (int) $reseller['id'];

        if ($access === Route::ADMIN) {
            throw new ApiException(403, 'FORBIDDEN', 'Admin key required');
        }
    }
}
