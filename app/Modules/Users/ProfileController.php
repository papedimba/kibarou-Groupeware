<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;

/** Espace personnel : coordonnées, mot de passe, sessions ouvertes. */
final class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $this->user();
        return $this->view('profile/show', [
            'currentPath' => '/profile',
            'sessions' => (new UserRepository($this->app->db()))->sessions((int) $user['id']),
            'currentSessionId' => $this->app->session()->id(),
            'myStatus' => $this->app->presence()->current([(int) $user['id']])[(int) $user['id']] ?? null,
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->user();
        try {
            $data = Validator::validate(
                $request->post,
                ['phone' => 'phone|max:30', 'phone_personal' => 'phone|max:30'],
                ['phone' => 'Téléphone professionnel', 'phone_personal' => 'Téléphone personnel'],
            );
        } catch (ValidationException $e) {
            return $this->back('/profile', $e->errors, $request->post);
        }
        $this->app->db()->update('users', ['phone' => $data['phone'], 'phone_personal' => $data['phone_personal'], 'updated_at' => now_utc()], ['id' => $user['id']]);
        $this->app->audit()->log((int) $user['id'], 'profile.updated', 'user', (int) $user['id']);
        return $this->redirect('/profile', 'Profil mis à jour.');
    }

    public function passwordForm(Request $request): Response
    {
        return $this->view('profile/password', ['currentPath' => '/profile']);
    }

    public function changePassword(Request $request): Response
    {
        try {
            $this->app->auth()->changePassword(
                $this->user(),
                (string) $request->input('current_password', ''),
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', ''),
            );
        } catch (ValidationException $e) {
            return $this->back('/profile/password', $e->errors);
        }
        return $this->redirect('/profile', 'Mot de passe modifié. Vos autres sessions ont été fermées.');
    }

    public function revokeSession(Request $request): Response
    {
        $user = $this->user();
        $sessionId = $request->str('session_id');
        if ($sessionId !== $this->app->session()->id()) {
            $this->app->db()->run('DELETE FROM sessions WHERE user_id = ? AND id = ?', [$user['id'], $sessionId]);
            $this->app->audit()->log((int) $user['id'], 'profile.session_revoked', 'user', (int) $user['id']);
        }
        return $this->redirect('/profile', 'Session fermée.');
    }
}
