<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PluginXchange\Http\ApiException;
use PluginXchange\Http\Request;

final class RequestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;
    /** @var array<string, mixed> */
    private array $get;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
    }

    public function testFromGlobalsReadsMethodPathQueryAndHeaders(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/api/v1/orders/?x=1';
        $_SERVER['HTTP_X_API_KEY'] = 'abc';
        $_GET = ['x' => '1'];

        $request = Request::fromGlobals();

        $this->assertSame('POST', $request->method);
        $this->assertSame('/api/v1/orders', $request->path);   // trailing slash dropped
        $this->assertSame(['x' => '1'], $request->query);
        $this->assertSame('abc', $request->header('X-API-Key'));
        $this->assertSame('abc', $request->header('x-api-key'));
        $this->assertNull($request->header('X-Other'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $request->requestId);
    }

    public function testRootPathStaysASlash(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->assertSame('/', Request::fromGlobals()->path);
    }

    public function testEachRequestGetsItsOwnId(): void
    {
        $this->assertNotSame((new Request('GET', '/'))->requestId, (new Request('GET', '/'))->requestId);
    }

    public function testJsonParsing(): void
    {
        $this->assertSame([], (new Request('POST', '/', [], [], ''))->json());
        $this->assertSame([], (new Request('POST', '/', [], [], '  '))->json());
        $this->assertSame(['a' => 1], (new Request('POST', '/', [], [], '{"a":1}'))->json());
        $this->assertSame(7, (new Request('POST', '/', [], [], '{"a":1}'))->bodySize());
    }

    public function testBodiesThatAreNotAJsonObjectAre400(): void
    {
        foreach (['{broken', '[1,2]', '"text"', '42', 'null'] as $body) {
            try {
                (new Request('POST', '/', [], [], $body))->json();
                $this->fail("Expected BAD_JSON for $body");
            } catch (ApiException $e) {
                $this->assertSame(400, $e->status);
                $this->assertSame('BAD_JSON', $e->errorCode);
            }
        }
    }
}
