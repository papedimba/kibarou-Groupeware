<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Database;
use App\Services\MailQueue;

/**
 * Récapitulatif quotidien (à partir de 7 h, heure de l'entreprise) : un email par personne
 * listant ses notifications « récapitulatif » non lues. Une seule fois par jour local.
 */
final class DigestService
{
    private const HOUR = 7;
    private const MAX_TITLES = 20;

    public function __construct(private readonly Database $db, private readonly MailQueue $mail, private readonly string $appUrl)
    {
    }

    /** @return int nombre d'emails mis en file */
    public function run(\DateTimeImmutable $nowLocal): int
    {
        if ((int) $nowLocal->format('G') < self::HOUR) {
            return 0;
        }
        $today = $nowLocal->format('Y-m-d');
        return $this->db->transaction(function () use ($today, $nowLocal) {
            // Verrou de ligne : deux exécutions simultanées ne produisent pas deux récapitulatifs.
            $last = $this->db->value("SELECT `value` FROM settings WHERE `key` = 'digest_last_date' FOR UPDATE");
            if ($last === $today) {
                return 0;
            }
            $this->db->run(
                "INSERT INTO settings (`key`, `value`, updated_at) VALUES ('digest_last_date', ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)",
                [$today],
            );
            $rows = $this->db->fetchAll(
                "SELECT n.id, n.user_id, n.title, u.email FROM notifications n
                 JOIN users u ON u.id = n.user_id AND u.status = 'active' AND u.deleted_at IS NULL
                 WHERE n.email_status = 'digest_pending' AND n.read_at IS NULL
                 ORDER BY n.user_id, n.id",
            );
            $byUser = [];
            foreach ($rows as $r) {
                $byUser[(int) $r['user_id']]['email'] = (string) $r['email'];
                $byUser[(int) $r['user_id']]['titles'][] = (string) $r['title'];
            }
            $date = $nowLocal->format('d/m/Y');
            foreach ($byUser as $u) {
                $titles = array_slice($u['titles'], 0, self::MAX_TITLES);
                $more = count($u['titles']) - count($titles);
                $lines = array_map(static fn ($t) => '- ' . $t, $titles);
                if ($more > 0) {
                    $lines[] = "et $more autres";
                }
                $base = rtrim($this->appUrl, '/');
                $this->mail->push($u['email'], "Vos notifications du $date", "Bonjour,\n\nVos notifications non lues :\n\n"
                    . implode("\n", $lines) . "\n\nLes voir : $base/notifications\nGérer mes notifications : $base/profile/notifications\n");
            }
            // Lues ou non, les notifications en attente sont traitées : aucune ne reviendra dans un autre récapitulatif.
            $this->db->run("UPDATE notifications SET email_status = 'digested' WHERE email_status = 'digest_pending'");
            return count($byUser);
        });
    }
}
