<?php

declare(strict_types=1);

namespace App\Core;

use App\Modules\Auth\AuthService;
use App\Modules\Auth\PasswordResetService;
use App\Services\AuditLogger;
use App\Services\MailQueue;
use App\Services\Rbac;
use Throwable;

/**
 * Point d'entrée de l'application : configuration, services partagés (créés à la demande)
 * et traitement d'une requête HTTP.
 */
final class App
{
    private static ?self $instance = null;
    /** @var array<string,object> */
    private array $services = [];

    /** @param array<string,mixed> $config */
    private function __construct(private array $config)
    {
    }

    /** @param array<string,mixed> $overrides surcharges (tests) fusionnées par section */
    public static function boot(string $root, array $overrides = []): self
    {
        Env::load($root . '/.env');
        $config = require $root . '/config/app.php';
        foreach ($overrides as $section => $values) {
            $config[$section] = array_replace($config[$section] ?? [], $values);
        }
        date_default_timezone_set('UTC');
        self::$instance = new self($config);
        return self::$instance;
    }

    public static function instance(): self
    {
        return self::$instance ?? throw new \LogicException('Application non initialisée.');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable():T $factory
     * @return T
     */
    private function service(string $id, callable $factory): object
    {
        /** @var T */
        return $this->services[$id] ??= $factory();
    }

    public function db(): Database
    {
        return $this->service(Database::class, fn () => new Database($this->config['db']));
    }

    public function session(): Session
    {
        return $this->service(Session::class, fn () => new Session($this->db(), $this->config['session']));
    }

    public function view(): View
    {
        return $this->service(View::class, fn () => new View($this->config['paths']['views']));
    }

    public function logger(): Logger
    {
        return $this->service(Logger::class, fn () => new Logger($this->config['paths']['storage'] . '/logs'));
    }

    public function rbac(): Rbac
    {
        return $this->service(Rbac::class, fn () => new Rbac($this->db()));
    }

    public function audit(): AuditLogger
    {
        return $this->service(AuditLogger::class, fn () => new AuditLogger($this->db()));
    }

    public function mailQueue(): MailQueue
    {
        return $this->service(MailQueue::class, fn () => new MailQueue($this->db()));
    }

    public function auth(): AuthService
    {
        return $this->service(AuthService::class, fn () => new AuthService(
            $this->db(),
            $this->session(),
            $this->audit(),
            $this->config['auth'],
        ));
    }

    public function passwordResets(): PasswordResetService
    {
        return $this->service(PasswordResetService::class, fn () => new PasswordResetService(
            $this->db(),
            $this->mailQueue(),
            $this->audit(),
            (int) $this->config['auth']['reset_token_minutes'],
            (int) $this->config['auth']['password_min_length'],
        ));
    }

    /** Réinitialise les services liés à une requête (utile pour enchaîner des requêtes en test). */
    public function resetRequestState(): void
    {
        unset($this->services[Session::class], $this->services[AuthService::class], $this->services[Rbac::class]);
    }

    public function handle(Request $request, Router $router): Response
    {
        $this->resetRequestState();
        try {
            $this->audit()->setIp($request->ip());
            $route = $router->match($request->method, $request->path);
            // L'installateur tourne avant que la table des sessions n'existe.
            if (!in_array('install', $route['middleware'], true)) {
                $this->session()->start($request);
            }
            $request->params = $route['params'];
            $response = (new Middleware($this))->run($route['middleware'], $request, function (Request $req) use ($route) {
                [$class, $method] = $route['handler'];
                return (new $class($this))->$method($req);
            });
        } catch (Throwable $e) {
            $response = (new ErrorHandler($this))->render($e, $request);
        }
        try {
            $this->session()->save($response);
        } catch (Throwable $e) {
            $this->logger()->error('Échec de sauvegarde de session : ' . $e->getMessage());
        }
        SecurityHeaders::apply($response, (bool) $this->config['session']['secure']);
        return $response;
    }
}
