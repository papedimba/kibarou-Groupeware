<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Modules\Notifications\Notifier;
use App\Services\AuditLogger;

final class AuthService
{
    private const GENERIC_ERROR = 'Email ou mot de passe incorrect.';

    /** @var array<string,mixed>|null|false false = pas encore chargé */
    private array|null|false $user = false;

    /** @param array{max_attempts_per_email:int,max_attempts_per_ip:int,lockout_minutes:int,reset_token_minutes:int,password_min_length:int} $config */
    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly AuditLogger $audit,
        private readonly array $config,
        private readonly Notifier $notifier,
    ) {
    }

    /** Utilisateur connecté, actif et non supprimé ; sinon null (et la session est fermée). */
    public function user(): ?array
    {
        if ($this->user !== false) {
            return $this->user;
        }
        $id = $this->session->userId();
        $this->user = $id === null ? null : $this->db->fetch(
            "SELECT * FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL",
            [$id],
        );
        if ($id !== null && $this->user === null) {
            $this->session->destroy();
        }
        return $this->user;
    }

    /**
     * @return array<string,mixed> l'utilisateur authentifié
     * @throws ValidationException|HttpException
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $this->guardThrottle($email, $ip);

        $user = $this->db->fetch('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
        // Hash factice pour un temps de réponse comparable si le compte n'existe pas.
        $hash = $user['password_hash'] ?? '$2y$10$UGXm2RypfYg2qITYTEqVQOztKctNhXdosjPmY13ot7raEuxIxwz4S';
        $valid = password_verify($password, (string) $hash) && $user !== null;

        if (!$valid) {
            $this->recordAttempt($email, $ip, false);
            $this->audit->log($user !== null ? (int) $user['id'] : null, 'auth.login_failed', 'user', $user !== null ? (int) $user['id'] : null, ['email' => $email]);
            throw new ValidationException(['email' => self::GENERIC_ERROR]);
        }
        if ($user['status'] !== 'active') {
            $this->recordAttempt($email, $ip, false);
            $this->audit->log((int) $user['id'], 'auth.login_disabled', 'user', (int) $user['id']);
            throw new ValidationException(['email' => 'Ce compte est désactivé. Contactez un administrateur.']);
        }

        $this->recordAttempt($email, $ip, true);
        $update = ['last_login_at' => now_utc()];
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = PasswordPolicy::hash($password);
        }
        $this->db->update('users', $update, ['id' => $user['id']]);
        $this->session->login((int) $user['id']);
        $this->audit->log((int) $user['id'], 'auth.login', 'user', (int) $user['id']);
        $this->user = false;
        return $user;
    }

    public function logout(): void
    {
        $user = $this->user();
        if ($user !== null) {
            $this->audit->log((int) $user['id'], 'auth.logout', 'user', (int) $user['id']);
        }
        $this->session->destroy();
        $this->user = null;
    }

    /**
     * Changement de mot de passe par l'utilisateur lui-même. Les autres sessions sont révoquées.
     * @param array<string,mixed> $user
     */
    public function changePassword(array $user, string $current, string $new, string $confirm): void
    {
        $errors = [];
        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = 'Le mot de passe actuel est incorrect.';
        } elseif ($new !== $confirm) {
            $errors['password_confirmation'] = 'Les deux mots de passe ne correspondent pas.';
        } elseif ($current === $new) {
            $errors['password'] = 'Le nouveau mot de passe doit être différent de l\'actuel.';
        } elseif (($policy = PasswordPolicy::check($new, $this->config['password_min_length'], (string) $user['email'])) !== null) {
            $errors['password'] = $policy;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->db->transaction(function () use ($user, $new) {
            $this->db->update('users', [
                'password_hash' => PasswordPolicy::hash($new),
                'must_change_password' => 0,
                'password_changed_at' => now_utc(),
                'updated_at' => now_utc(),
            ], ['id' => $user['id']]);
            $this->db->run('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$user['id'], (string) $this->session->id()]);
        });
        $this->audit->log((int) $user['id'], 'auth.password_changed', 'user', (int) $user['id']);
        // Premier changement imposé (mot de passe temporaire) : fin d'activation, pas une alerte de sécurité.
        if ((int) $user['must_change_password'] !== 1) {
            $this->notifier->notify((int) $user['id'], 'security.password_changed', 'Votre mot de passe a été modifié', '/profile', 'Si vous n\'êtes pas à l\'origine de ce changement, contactez immédiatement un administrateur.');
        }
        $this->user = false;
    }

    private function guardThrottle(string $email, string $ip): void
    {
        $minutes = $this->config['lockout_minutes'];
        $row = $this->db->fetch(
            'SELECT
                (SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE) AS by_email,
                (SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE) AS by_ip',
            [$email, $minutes, $ip, $minutes],
        );
        if ((int) $row['by_email'] >= $this->config['max_attempts_per_email'] || (int) $row['by_ip'] >= $this->config['max_attempts_per_ip']) {
            $this->audit->log(null, 'auth.throttled', null, null, ['email' => $email]);
            throw new HttpException(429, "Trop de tentatives de connexion. Réessayez dans $minutes minutes.");
        }
    }

    private function recordAttempt(string $email, string $ip, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'email' => mb_substr($email, 0, 190),
            'ip_address' => $ip,
            'success' => $success ? 1 : 0,
            'created_at' => now_utc(),
        ]);
        if ($success) {
            // Une connexion réussie remet à zéro le compteur de cet email.
            $this->db->run('DELETE FROM login_attempts WHERE email = ? AND success = 0', [$email]);
        }
    }
}
