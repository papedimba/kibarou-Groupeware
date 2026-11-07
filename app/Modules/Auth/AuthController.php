<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login');
    }

    public function login(Request $request): Response
    {
        try {
            $this->app->auth()->attempt($request->str('email'), (string) $request->input('password', ''), $request->ip());
        } catch (ValidationException $e) {
            return $this->back('/login', $e->errors, ['email' => $request->str('email')]);
        }
        $intended = (string) $this->app->session()->get('intended', '/');
        $this->app->session()->forget('intended');
        // Seuls les chemins internes sont acceptés (pas de redirection ouverte).
        return $this->redirect(preg_match('#^/[a-z0-9/_-]*$#i', $intended) ? $intended : '/');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth()->logout();
        return Response::redirect(url('/login'));
    }

    public function showForgot(Request $request): Response
    {
        return $this->view('auth/forgot');
    }

    public function forgot(Request $request): Response
    {
        $email = $request->str('email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->resets()->request($email, (string) $this->app->config('app.url'));
        }
        return $this->redirect('/login', 'Si un compte actif correspond à cette adresse, un email de réinitialisation vient d\'être envoyé.');
    }

    public function showReset(Request $request): Response
    {
        $token = (string) ($request->params['token'] ?? '');
        if (!$this->resets()->isValid($token)) {
            return $this->redirect('/password/forgot', 'Ce lien est invalide ou a expiré. Faites une nouvelle demande.', 'danger');
        }
        return $this->view('auth/reset', ['token' => $token]);
    }

    public function reset(Request $request): Response
    {
        $token = (string) ($request->params['token'] ?? '');
        try {
            $this->resets()->reset($token, (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        } catch (ValidationException $e) {
            return $this->back('/password/reset/' . $token, $e->errors);
        }
        return $this->redirect('/login', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');
    }

    private function resets(): PasswordResetService
    {
        return $this->app->passwordResets();
    }
}
