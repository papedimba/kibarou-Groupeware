<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routeur centralisé. Chaque route déclare son contrôleur et sa liste de middlewares
 * (ex. ['auth', 'csrf', 'perm:users.manage']).
 */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:array{0:class-string,1:string},middleware:list<string>}> */
    private array $routes = [];
    /** @var list<string> */
    private array $groupMiddleware = [];

    /**
     * @param array{0:class-string,1:string} $handler
     * @param list<string> $middleware
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        // {id} : entier ; {nom:token} : jeton hexadécimal de 64 caractères.
        $regex = '#^' . preg_replace_callback(
            '#\{(\w+)(?::(token))?\}#',
            static fn ($m) => '(?P<' . $m[1] . '>' . (($m[2] ?? '') === 'token' ? '[a-f0-9]{64}' : '[0-9]+') . ')',
            rtrim($pattern, '/') ?: '/',
        ) . '$#';
        $this->routes[] = [
            'method' => $method,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    /** @param array{0:class-string,1:string} $h @param list<string> $m */
    public function get(string $p, array $h, array $m = []): void
    {
        $this->add('GET', $p, $h, $m);
    }

    /** @param array{0:class-string,1:string} $h @param list<string> $m */
    public function post(string $p, array $h, array $m = []): void
    {
        $this->add('POST', $p, $h, $m);
    }

    /** @param list<string> $middleware */
    public function group(array $middleware, callable $define): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = [...$previous, ...$middleware];
        $define($this);
        $this->groupMiddleware = $previous;
    }

    /**
     * Les paramètres {id} n'acceptent que des entiers : tout autre format renvoie 404.
     * @return array{handler:array{0:class-string,1:string},middleware:list<string>,params:array<string,string>}
     */
    public function match(string $method, string $path): array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed = true;
                continue;
            }
            return [
                'handler' => $route['handler'],
                'middleware' => $route['middleware'],
                'params' => array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY),
            ];
        }
        throw new HttpException($allowed ? 405 : 404);
    }
}
