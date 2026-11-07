<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @var array<string,string> */
    public array $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    public array $cookies = [];

    public function __construct(public string $body = '', public int $status = 200)
    {
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $r = new self('', $status);
        $r->headers['Location'] = $to;
        return $r;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $r = new self((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $status);
        $r->headers['Content-Type'] = 'application/json; charset=UTF-8';
        return $r;
    }

    /** @param array<string,mixed> $options */
    public function withCookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        foreach ($this->cookies as $c) {
            setcookie($c['name'], $c['value'], $c['options']);
        }
        echo $this->body;
    }
}
