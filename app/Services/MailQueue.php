<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/**
 * File d'attente des emails. L'application n'envoie jamais d'email pendant une requête HTTP :
 * elle insère en base, la tâche CRON `mail:send` envoie. Un SMTP en panne ne bloque donc rien.
 */
final class MailQueue
{
    public function __construct(private readonly Database $db)
    {
    }

    public function push(string $to, string $subject, string $bodyText): int
    {
        return $this->db->insert('mail_queue', [
            'to_email' => $to,
            'subject' => mb_substr($subject, 0, 255),
            'body_text' => $bodyText,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now_utc(),
            'created_at' => now_utc(),
        ]);
    }

    /**
     * Envoie un lot d'emails en attente. Le verrou par UPDATE conditionnel évite qu'une
     * exécution CRON concurrente envoie deux fois le même email.
     * @param callable(string,string,string):void $send
     * @return array{sent:int,failed:int}
     */
    public function process(callable $send, int $batchSize, int $maxAttempts): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, to_email, subject, body_text, attempts FROM mail_queue
             WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, $batchSize),
        );
        $stats = ['sent' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $locked = $this->db->run(
                "UPDATE mail_queue SET status = 'sending', attempts = attempts + 1, locked_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'",
                [$row['id']],
            )->rowCount();
            if ($locked === 0) {
                continue;
            }
            try {
                $send((string) $row['to_email'], (string) $row['subject'], (string) $row['body_text']);
                $this->db->run("UPDATE mail_queue SET status = 'sent', sent_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?", [$row['id']]);
                $stats['sent']++;
            } catch (Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                // Nouvel essai avec délai croissant (5, 10, 20... minutes) jusqu'au maximum.
                $this->db->run(
                    'UPDATE mail_queue SET status = ?, last_error = ?, available_at = UTC_TIMESTAMP() + INTERVAL ? MINUTE WHERE id = ?',
                    [$attempts >= $maxAttempts ? 'failed' : 'pending', mb_substr($e->getMessage(), 0, 500), 5 * (2 ** ($attempts - 1)), $row['id']],
                );
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /** Remet en file les emails bloqués en « sending » (processus CRON interrompu). */
    public function releaseStale(): int
    {
        return $this->db->run(
            "UPDATE mail_queue SET status = 'pending' WHERE status = 'sending' AND locked_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE",
        )->rowCount();
    }
}
