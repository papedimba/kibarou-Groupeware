<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Nettoyage périodique selon la politique de conservation (appelé par CRON). */
final class Maintenance
{
    /**
     * @param array{login_attempts_days:int,audit_logs_days:int,mail_queue_days:int} $retention
     * @param array{idle_minutes:int,absolute_hours:int} $session
     */
    public function __construct(private readonly Database $db, private readonly array $retention, private readonly array $session)
    {
    }

    /** @return array<string,int> lignes supprimées par catégorie */
    public function run(): array
    {
        $r = $this->retention;
        return [
            'sessions' => $this->delete(
                'DELETE FROM sessions WHERE last_activity_at < UTC_TIMESTAMP() - INTERVAL ? MINUTE OR created_at < UTC_TIMESTAMP() - INTERVAL ? HOUR',
                [$this->session['idle_minutes'], $this->session['absolute_hours']],
            ),
            'login_attempts' => $this->delete('DELETE FROM login_attempts WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY', [$r['login_attempts_days']]),
            'password_resets' => $this->delete('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY OR used_at < UTC_TIMESTAMP() - INTERVAL 1 DAY', []),
            'mail_queue' => $this->delete("DELETE FROM mail_queue WHERE status IN ('sent','failed') AND created_at < UTC_TIMESTAMP() - INTERVAL ? DAY", [$r['mail_queue_days']]),
            'audit_logs' => $this->delete('DELETE FROM audit_logs WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY', [$r['audit_logs_days']]),
        ];
    }

    /** @param list<mixed> $params */
    private function delete(string $sql, array $params): int
    {
        // Suppression par lots pour ne pas verrouiller longtemps les tables sur un mutualisé.
        $total = 0;
        do {
            $n = $this->db->run($sql . ' LIMIT 1000', $params)->rowCount();
            $total += $n;
        } while ($n === 1000);
        return $total;
    }
}
