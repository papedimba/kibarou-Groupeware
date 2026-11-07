<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Database;
use App\Core\ValidationException;
use App\Services\AuditLogger;
use App\Services\MailQueue;

/**
 * Réinitialisation du mot de passe par lien email à usage unique.
 * La réponse est identique que le compte existe ou non (pas d'énumération des comptes).
 */
final class PasswordResetService
{
    private const MAX_REQUESTS_PER_HOUR = 3;

    public function __construct(
        private readonly Database $db,
        private readonly MailQueue $mail,
        private readonly AuditLogger $audit,
        private readonly int $tokenMinutes,
        private readonly int $passwordMinLength,
    ) {
    }

    /** Retourne le jeton créé (pour les tests), ou null si aucune action n'a été faite. */
    public function request(string $email, string $appUrl): ?string
    {
        $email = mb_strtolower(trim($email));
        $user = $this->db->fetch(
            "SELECT id, email, first_name FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL",
            [$email],
        );
        if ($user === null) {
            return null;
        }
        $recent = (int) $this->db->value(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR',
            [$user['id']],
        );
        if ($recent >= self::MAX_REQUESTS_PER_HOUR) {
            return null;
        }
        return $this->issue($user, $appUrl, $this->tokenMinutes, 'Réinitialisation de votre mot de passe',
            "Une réinitialisation de votre mot de passe Kibarou Groupware a été demandée.", 'auth.reset_requested');
    }

    /** Lien d'activation envoyé à un nouveau compte (valable 72 h). */
    public function invite(int $userId, string $appUrl): string
    {
        $user = $this->db->fetch('SELECT id, email, first_name FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            throw new \RuntimeException("Utilisateur $userId introuvable.");
        }
        return $this->issue($user, $appUrl, 72 * 60, 'Activation de votre compte Kibarou Groupware',
            "Un compte Kibarou Groupware a été créé pour vous. Choisissez votre mot de passe pour l'activer.", 'auth.invitation_sent');
    }

    /** @param array<string,mixed> $user */
    private function issue(array $user, string $appUrl, int $minutes, string $subject, string $intro, string $auditAction): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->transaction(function () use ($user, $token, $minutes) {
            $this->db->run('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$user['id']]);
            $this->db->insert('password_resets', [
                'user_id' => $user['id'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $minutes * 60),
                'created_at' => now_utc(),
            ]);
        });
        $link = rtrim($appUrl, '/') . '/password/reset/' . $token;
        $validity = $minutes >= 120 ? intdiv($minutes, 60) . ' heures' : "$minutes minutes";
        $this->mail->push(
            (string) $user['email'],
            $subject,
            "Bonjour {$user['first_name']},\n\n$intro\n"
            . "Ce lien est valable $validity et ne peut servir qu'une fois :\n\n$link\n\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n",
        );
        $this->audit->log((int) $user['id'], $auditAction, 'user', (int) $user['id']);
        return $token;
    }

    public function isValid(string $token): bool
    {
        return $this->findValid($token, false) !== null;
    }

    public function reset(string $token, string $password, string $confirm): void
    {
        $this->db->transaction(function () use ($token, $password, $confirm) {
            $row = $this->findValid($token, true);
            if ($row === null) {
                throw new ValidationException(['password' => 'Ce lien est invalide ou a expiré. Faites une nouvelle demande.']);
            }
            if ($password !== $confirm) {
                throw new ValidationException(['password_confirmation' => 'Les deux mots de passe ne correspondent pas.']);
            }
            $policy = PasswordPolicy::check($password, $this->passwordMinLength, (string) $row['email']);
            if ($policy !== null) {
                throw new ValidationException(['password' => $policy]);
            }
            $this->db->update('users', [
                'password_hash' => PasswordPolicy::hash($password),
                'must_change_password' => 0,
                'password_changed_at' => now_utc(),
                'updated_at' => now_utc(),
            ], ['id' => $row['user_id']]);
            $this->db->update('password_resets', ['used_at' => now_utc()], ['id' => $row['id']]);
            $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$row['user_id']]);
            $this->db->run('DELETE FROM login_attempts WHERE email = ? AND success = 0', [$row['email']]);
            $this->audit->log((int) $row['user_id'], 'auth.reset_completed', 'user', (int) $row['user_id']);
        });
    }

    /** @return array<string,mixed>|null */
    private function findValid(string $token, bool $lock): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return $this->db->fetch(
            "SELECT pr.id, pr.user_id, u.email FROM password_resets pr
             JOIN users u ON u.id = pr.user_id AND u.status = 'active' AND u.deleted_at IS NULL
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > UTC_TIMESTAMP()"
            . ($lock ? ' FOR UPDATE' : ''),
            [hash('sha256', $token)],
        );
    }
}
