<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/users/{id}', ['C', 'show'], ['auth']);
        $this->router->get('/reset/{token:token}', ['C', 'reset']);
        $this->router->group(['auth', 'perm:x'], function (Router $r) {
            $r->post('/users', ['C', 'store'], ['extra']);
        });
    }

    public function testMatchesIntegerParameter(): void
    {
        $m = $this->router->match('GET', '/users/42');
        $this->assertSame(['id' => '42'], $m['params']);
        $this->assertSame(['auth'], $m['middleware']);
    }

    public function testRejectsNonIntegerParameter(): void
    {
        $this->expectExceptionObject(new HttpException(404));
        $this->router->match('GET', '/users/42abc');
    }

    public function testTokenParameterRequiresHex64(): void
    {
        $token = str_repeat('ab', 32);
        $this->assertSame($token, $this->router->match('GET', "/reset/$token")['params']['token']);
        $this->expectException(HttpException::class);
        $this->router->match('GET', '/reset/../../etc');
    }

    public function testGroupMiddlewareIsPrepended(): void
    {
        $this->assertSame(['auth', 'perm:x', 'extra'], $this->router->match('POST', '/users')['middleware']);
    }

    public function testWrongMethodGives405(): void
    {
        try {
            $this->router->match('DELETE', '/users/1');
            $this->fail('405 attendu');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status);
        }
    }
}
