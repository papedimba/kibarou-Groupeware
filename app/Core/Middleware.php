<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Exécute les contrôles déclarés par la route avant le contrôleur.
 * Le contrôle CSRF est appliqué à toute requête non-GET, sans exception.
 */
final class Middleware
{
    /** Chemins accessibles alors qu'un changement de mot de passe est imposé. */
    private const PASSWORD_CHANGE_ALLOWED = ['/profile/password', '/logout'];

    public function __construct(private readonly App $app)
    {
    }

    /** @param list<string> $names */
    public function run(array $names, Request $request, callable $controller): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true) && !in_array('install', $names, true)) {
            $this->csrf($request);
        }
        foreach ($names as $name) {
            [$type, $arg] = array_pad(explode(':', $name, 2), 2, '');
            match ($type) {
                'auth' => $this->auth($request),
                'guest' => $this->guest(),
                'perm' => $this->permission($arg),
                'install' => $this->install($request),
                default => throw new \LogicException("Middleware inconnu : $type"),
            };
        }
        return $controller($request);
    }

    private function csrf(Request $request): void
    {
        $token = (string) ($request->post['_csrf'] ?? $request->header('X-CSRF-Token') ?? '');
        if (!$this->app->session()->verifyCsrf($token)) {
            throw new HttpException(419);
        }
    }

    private function auth(Request $request): void
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            if (!$request->wantsJson() && $request->method === 'GET') {
                $this->app->session()->set('intended', $request->path);
            }
            throw new HttpException(401);
        }
        if ((int) $user['must_change_password'] === 1 && !in_array($request->path, self::PASSWORD_CHANGE_ALLOWED, true)) {
            throw new RedirectException(url('/profile/password'));
        }
    }

    private function guest(): void
    {
        if ($this->app->auth()->user() !== null) {
            throw new RedirectException(url('/'));
        }
    }

    /** L'installateur exige INSTALL_TOKEN et l'absence de super administrateur ; sinon il n'existe pas. */
    private function install(Request $request): void
    {
        $expected = (string) $this->app->config('app.install_token', '');
        $given = $request->str('token');
        $installer = new \App\Modules\Install\Installer(
            $this->app->db(),
            (string) $this->app->config('paths.migrations'),
            (int) $this->app->config('auth.password_min_length'),
        );
        if (strlen($expected) < 32 || !hash_equals($expected, $given) || !$installer->needsInstall()) {
            throw new HttpException(404);
        }
    }

    private function permission(string $permission): void
    {
        $user = $this->app->auth()->user();
        if ($user === null || !$this->app->rbac()->can($user, $permission)) {
            throw new HttpException(403);
        }
    }
}
