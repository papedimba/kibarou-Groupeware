<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Database;
use App\Services\MailQueue;

/**
 * Centrale d'envoi : enregistre la notification et, selon les préférences, met un email en file.
 * Aucun envoi SMTP ici : la tâche CRON envoie la file (et le récapitulatif quotidien).
 */
final class Notifier
{
    public function __construct(private readonly Database $db, private readonly MailQueue $mail, private readonly string $appUrl)
    {
    }

    /** Retourne l'id créé, ou null si le destinataire n'est pas un compte actif. */
    public function notify(int $userId, string $type, string $title, ?string $url = null, ?string $body = null, ?int $actorId = null): ?int
    {
        $pref = $this->preferences($userId)[$type] ?? NotificationTypes::get($type);
        $user = $this->db->fetch("SELECT email FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$userId]);
        if ($user === null) {
            return null;
        }
        // Liens internes uniquement (pas de « //hôte » ni d'URL absolue).
        $url = $url !== null && preg_match('#^/(?!/)#', $url) ? mb_substr($url, 0, 255) : null;
        $status = match ($pref['email']) {
            'immediate' => 'queued',
            'digest' => 'digest_pending',
            default => 'none',
        };
        $id = $this->db->insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($title, 0, 190),
            'body' => $body !== null ? mb_substr($body, 0, 500) : null,
            'url' => $url,
            'actor_id' => $actorId,
            'created_at' => now_utc(),
            'email_status' => $status,
        ]);
        if ($status === 'queued') {
            $base = rtrim($this->appUrl, '/');
            $this->mail->push((string) $user['email'], $title, implode("\n\n", array_filter([
                $title,
                $body,
                $url !== null ? $base . $url : null,
                "Gérer mes notifications : $base/profile/notifications",
            ])) . "\n");
        }
        return $id;
    }

    /** @return array<string,array{label:string,email:string,browser:bool,mandatory:bool}> catalogue fusionné avec les choix de la personne */
    public function preferences(int $userId): array
    {
        $saved = [];
        foreach ($this->db->fetchAll('SELECT type, email, browser FROM notification_preferences WHERE user_id = ?', [$userId]) as $row) {
            $saved[(string) $row['type']] = $row;
        }
        $out = [];
        foreach (NotificationTypes::all() as $type => $def) {
            if (isset($saved[$type]) && !$def['mandatory']) {
                $def['email'] = (string) $saved[$type]['email'];
                $def['browser'] = (int) $saved[$type]['browser'] === 1;
            }
            $out[$type] = $def;
        }
        return $out;
    }

    /**
     * Enregistre les choix du formulaire. Valeur inconnue → choix actuel conservé ; types obligatoires jamais enregistrés.
     * @param array<string,mixed> $input ['email' => [type => immediate|digest|off], 'browser' => [type => 1]]
     */
    public function savePreferences(int $userId, array $input): void
    {
        $current = $this->preferences($userId);
        $email = is_array($input['email'] ?? null) ? $input['email'] : [];
        $browser = is_array($input['browser'] ?? null) ? $input['browser'] : [];
        foreach (NotificationTypes::all() as $type => $def) {
            if ($def['mandatory']) {
                continue;
            }
            $choice = $email[$type] ?? null;
            $this->db->run(
                'REPLACE INTO notification_preferences (user_id, type, email, browser) VALUES (?, ?, ?, ?)',
                [$userId, $type, in_array($choice, ['immediate', 'digest', 'off'], true) ? $choice : $current[$type]['email'], isset($browser[$type]) ? 1 : 0],
            );
        }
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }
}
