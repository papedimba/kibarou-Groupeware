<?php

declare(strict_types=1);

namespace App\Modules\Install;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

/**
 * Assistant d'installation web (déploiement FTP sans SSH). Protégé par INSTALL_TOKEN
 * (vérifié par le middleware `install`) qui tient aussi lieu de jeton anti-CSRF.
 * Inaccessible (404) dès qu'un super administrateur existe.
 */
final class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        $applied = $this->installer()->migrate();
        return $this->installView(['applied' => $applied, 'token' => $request->str('token'), 'errors' => [], 'old' => []]);
    }

    public function store(Request $request): Response
    {
        try {
            $this->installer()->createSuperAdmin($request->post);
        } catch (ValidationException $e) {
            return $this->installView([
                'applied' => [],
                'token' => $request->str('token'),
                'errors' => $e->errors,
                'old' => array_intersect_key($request->post, array_flip(['email', 'first_name', 'last_name'])),
            ], 422);
        }
        return new Response($this->app->view()->render('install/done'));
    }

    /** @param array<string,mixed> $data */
    private function installView(array $data, int $status = 200): Response
    {
        return new Response($this->app->view()->render('install/form', $data), $status);
    }

    private function installer(): Installer
    {
        return new Installer(
            $this->app->db(),
            (string) $this->app->config('paths.migrations'),
            (int) $this->app->config('auth.password_min_length'),
        );
    }
}
