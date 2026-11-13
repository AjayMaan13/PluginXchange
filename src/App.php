<?php

declare(strict_types=1);

namespace PluginXchange;

use PluginXchange\Controllers\OrderController;
use PluginXchange\Controllers\ProductController;
use PluginXchange\Controllers\ResellerController;
use PluginXchange\Http\ApiException;
use PluginXchange\Http\Auth;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Request;
use PluginXchange\Http\Route;
use PluginXchange\Http\Router;
use PluginXchange\Repositories\ResellerRepository;
use Throwable;

final class App
{
    private readonly Router $router;
    private readonly Auth $auth;

    public function __construct(?string $adminKey = null)
    {
        $this->auth = new Auth(new ResellerRepository(), $adminKey ?? (string) getenv('ADMIN_API_KEY'));
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function router(): Router
    {
        return $this->router;
    }

    /** Runs the request through routing and auth. Never throws: every failure becomes a JSON error. */
    public function handle(Request $request): JsonResponse
    {
        try {
            [$route, $params] = $this->router->match($request->method, $request->path);
            $request->params = $params;
            $this->auth->authenticate($request, $route->access);
            $response = ($route->handler)($request);
        } catch (ApiException $e) {
            $response = $this->error($request, $e->status, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            error_log(sprintf('[%s] %s: %s', $request->requestId, $e::class, $e->getMessage()));
            $response = $this->error($request, 500, 'INTERNAL_ERROR', 'Internal server error');
        }

        $response->headers['X-Request-Id'] = $request->requestId;
        return $response;
    }

    private function error(Request $request, int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse($status, ['error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $request->requestId,
        ]]);
    }

    private function registerRoutes(): void
    {
        $products = new ProductController();
        $resellers = new ResellerController();
        $orders = new OrderController();

        $this->router->add('GET', '/', fn() => new JsonResponse(200, ['status' => 'ok']), Route::PUBLIC);
        $this->router->add('GET', '/health', function (): JsonResponse {
            return Database::ping()
                ? new JsonResponse(200, ['status' => 'ok', 'db' => 'up'])
                : new JsonResponse(503, ['status' => 'degraded', 'db' => 'down']);
        }, Route::PUBLIC);

        $this->router->add('GET', '/api/v1/products', $products->index(...));
        $this->router->add('GET', '/api/v1/products/{id}', $products->show(...));
        $this->router->add('POST', '/api/v1/orders', $orders->create(...));
        $this->router->add('GET', '/api/v1/orders/{id}', $orders->show(...));
        $this->router->add('POST', '/api/v1/resellers', $resellers->create(...), Route::ADMIN);
    }
}
