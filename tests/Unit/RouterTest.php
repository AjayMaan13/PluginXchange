<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PluginXchange\Http\ApiException;
use PluginXchange\Http\JsonResponse;
use PluginXchange\Http\Router;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->add('GET', '/items', fn() => new JsonResponse(200, []));
        $router->add('GET', '/items/{id}', fn() => new JsonResponse(200, []));
        return $router;
    }

    public function testMatchesStaticAndParamRoutes(): void
    {
        [$route, $params] = $this->router()->match('GET', '/items/42');
        $this->assertSame('GET', $route->method);
        $this->assertSame(['id' => '42'], $params);

        [, $params] = $this->router()->match('GET', '/items');
        $this->assertSame([], $params);
    }

    public function testUnknownPathIs404(): void
    {
        try {
            $this->router()->match('GET', '/nope');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('NOT_FOUND', $e->errorCode);
        }
    }

    public function testWrongMethodIs405(): void
    {
        try {
            $this->router()->match('DELETE', '/items/1');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(405, $e->status);
            $this->assertSame('METHOD_NOT_ALLOWED', $e->errorCode);
        }
    }
}
