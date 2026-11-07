<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> Paramètres de route résolus par le routeur. */
    public array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $cookies
     * @param array<string,mixed> $files
     * @param array<string,mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $post = $_POST;
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_starts_with($type, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            $post = is_array($decoded) ? $decoded : [];
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($path, '/'),
            $_GET,
            $post,
            $_COOKIE,
            $_FILES,
            $_SERVER,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) filter_var($this->input($key, $default), FILTER_VALIDATE_INT, ['options' => ['default' => $default]]);
    }

    /** @return list<int> */
    public function intList(string $key): array
    {
        $values = $this->input($key, []);
        return is_array($values) ? array_values(array_unique(array_map('intval', $values))) : [];
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function ip(): string
    {
        // Pas de confiance dans X-Forwarded-For : falsifiable sans reverse proxy connu.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path, '/api/')
            || str_contains((string) $this->header('Accept'), 'application/json');
    }
}
