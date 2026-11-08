<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\AuditLogger;
use App\Services\Rbac;

/**
 * Salons (ouverts à toute l'entreprise, historique complet) et salons de service (tenus à jour d'après l'annuaire).
 * Envoi, pièces jointes, réactions et lecture restent dans ChatService : un salon est une conversation comme une autre.
 */
final class ChannelService
{
    private const SERVICE_SALON = 'Le salon d\'un service suit l\'annuaire.';

    public function __construct(
        private readonly Database $db,
        private readonly ChatService $chat,
        private readonly Rbac $rbac,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string,mixed> $actor */
    public function create(array $actor, string $name, string $description): int
    {
        if (!$this->rbac->can($actor, 'chat.channels.manage')) {
            throw new HttpException(403);
        }
        [$name, $description] = $this->valid($name, $description, null);
        $me = (int) $actor['id'];
        $cid = $this->db->transaction(function () use ($me, $name, $description) {
            $cid = $this->db->insert('conversations', ['type' => 'channel', 'name' => $name, 'description' => $description,
                'created_by' => $me, 'created_at' => now_utc(), 'last_event_at' => now_utc()]);
            $created = $this->chat->event($cid, 'created', $me);
            $this->enter($cid, $me, $created);
            return $cid;
        });
        $this->audit->log($me, 'chat.channel_created', 'conversation', $cid, ['name' => $name]);
        return $cid;
    }

    /** Rejoindre un salon, archivé ou non (on y lit tout l'historique). @param array<string,mixed> $actor */
    public function join(array $actor, int $cid): void
    {
        $this->channel($cid, 'channel');
        $me = (int) $actor['id'];
        if ($this->db->value('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL', [$cid, $me]) !== null) {
            return;
        }
        $this->db->transaction(function () use ($cid, $me) {
            $this->enter($cid, $me, $this->chat->event($cid, 'member_added', $me, $me));
        });
    }

    /** @param array<string,mixed> $actor */
    public function leave(array $actor, int $cid): void
    {
        $me = (int) $actor['id'];
        $m = $this->chat->requireMember($me, $cid);
        if ($m['type'] === 'department') {
            throw new ValidationException(['members' => 'On ne peut pas quitter le salon de son service.']);
        }
        if ($m['type'] !== 'channel') {
            throw new HttpException(404);
        }
        $this->db->transaction(function () use ($cid, $me) {
            $this->chat->event($cid, 'member_left', $me, $me);
            $this->db->run('UPDATE conversation_members SET left_at = UTC_TIMESTAMP() WHERE conversation_id = ? AND user_id = ?', [$cid, $me]);
        });
    }

    /** @param array<string,mixed> $actor */
    public function update(array $actor, int $cid, string $name, string $description): void
    {
        $c = $this->manageable($actor, $cid);
        [$name, $description] = $this->valid($name, $description, $c['archived_at'] === null ? $cid : null, $c['archived_at'] !== null);
        $this->db->transaction(function () use ($actor, $cid, $c, $name, $description) {
            $this->db->update('conversations', ['name' => $name, 'description' => $description], ['id' => $cid]);
            if ($name !== $c['name']) {
                $this->chat->event($cid, 'renamed', (int) $actor['id'], null, null, $name);
            }
        });
        $this->audit->log((int) $actor['id'], 'chat.channel_updated', 'conversation', $cid, ['name' => $name]);
    }

    /** @param array<string,mixed> $actor */
    public function archive(array $actor, int $cid): void
    {
        $c = $this->manageable($actor, $cid);
        if ($c['archived_at'] !== null) {
            return;
        }
        $this->setArchived($cid, true, (int) $actor['id']);
        $this->audit->log((int) $actor['id'], 'chat.channel_archived', 'conversation', $cid, ['name' => $c['name']]);
    }

    /** @param array<string,mixed> $actor */
    public function unarchive(array $actor, int $cid): void
    {
        $c = $this->manageable($actor, $cid);
        if ($c['archived_at'] === null) {
            return;
        }
        $this->valid((string) $c['name'], (string) ($c['description'] ?? ''), $cid);
        $this->setArchived($cid, false, (int) $actor['id']);
        $this->audit->log((int) $actor['id'], 'chat.channel_updated', 'conversation', $cid, ['name' => $c['name'], 'status' => 'rouvert']);
    }

    /**
     * Administrateurs et directeurs : tous les salons ; chef de service : ceux qu'il a créés (décision de l'utilisateur).
     * @param array<string,mixed> $user @param array<string,mixed> $conversation
     */
    public function canManage(array $user, array $conversation): bool
    {
        return ($conversation['type'] ?? '') === 'channel'
            && $this->rbac->can($user, 'chat.channels.manage')
            && ($this->rbac->can($user, 'admin.access') || $this->rbac->hasRole($user, 'directeur') || (int) $conversation['created_by'] === (int) $user['id']);
    }

    /**
     * Salons ouverts (ou archivés), par nom.
     * @param array<string,mixed> $user
     * @return list<array<string,mixed>>
     */
    public function list(array $user, bool $archived): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.type, c.name, c.description, c.last_event_at, c.archived_at, c.created_by,
                    (SELECT COUNT(*) FROM conversation_members m WHERE m.conversation_id = c.id AND m.left_at IS NULL) AS members,
                    EXISTS (SELECT 1 FROM conversation_members m WHERE m.conversation_id = c.id AND m.user_id = ? AND m.left_at IS NULL) AS member
             FROM conversations c WHERE c.type = 'channel' AND c.archived_at IS " . ($archived ? 'NOT NULL' : 'NULL') . ' ORDER BY c.name',
            [(int) $user['id']],
        );
        return array_map(function ($r) use ($user) {
            $r['id'] = (int) $r['id'];
            $r['members'] = (int) $r['members'];
            $r['member'] = (bool) $r['member'];
            $r['can_manage'] = $this->canManage($user, $r);
            return $r;
        }, $rows);
    }

    /** Alertes de cette conversation pour soi (tout type). @param array<string,mixed> $actor */
    public function setNotify(array $actor, int $cid, bool $on): void
    {
        $this->chat->requireMember((int) $actor['id'], $cid);
        $this->db->run('UPDATE conversation_members SET notify = ? WHERE conversation_id = ? AND user_id = ?', [$on ? 1 : 0, $cid, (int) $actor['id']]);
    }

    /**
     * Rapproche les salons de service de l'annuaire : un salon par service, nom et état du service,
     * membres = comptes actifs du service ayant accès à la messagerie. Idempotente ; ni audit ni notification.
     * Appelée après chaque modification de compte ou de service, et par cron:run (filet de sécurité).
     * @return array{created:int, added:int, removed:int}
     */
    public function syncDepartments(): array
    {
        $count = ['created' => 0, 'added' => 0, 'removed' => 0];
        $salons = array_column($this->db->fetchAll("SELECT * FROM conversations WHERE type = 'department' AND department_id IS NOT NULL"), null, 'department_id');
        foreach ($this->db->fetchAll('SELECT id, name, is_active FROM departments ORDER BY id') as $d) {
            $salon = $salons[$d['id']] ?? null;
            if ($salon === null && !(int) $d['is_active']) {
                continue;
            }
            $this->db->transaction(function () use ($d, $salon, &$count) {
                if ($salon === null) {
                    $cid = $this->db->insert('conversations', ['type' => 'department', 'name' => $d['name'], 'department_id' => $d['id'],
                        'created_at' => now_utc(), 'last_event_at' => now_utc()]);
                    $this->chat->event($cid, 'created', null);
                    $salon = ['id' => $cid, 'name' => $d['name'], 'archived_at' => null];
                    $count['created']++;
                }
                $cid = (int) $salon['id'];
                if ($salon['name'] !== $d['name']) {
                    $this->db->update('conversations', ['name' => $d['name']], ['id' => $cid]);
                    $this->chat->event($cid, 'renamed', null, null, null, $d['name']);
                }
                if ((int) $d['is_active'] === ($salon['archived_at'] !== null ? 1 : 0)) {
                    $this->setArchived($cid, !(int) $d['is_active'], null);
                }
                $expected = array_map('intval', array_column($this->db->fetchAll(
                    "SELECT u.id FROM users u WHERE u.department_id = ? AND u.status = 'active' AND u.deleted_at IS NULL
                       AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                                   LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
                                   WHERE ur.user_id = u.id AND (r.slug = 'super_admin' OR p.slug = 'chat.use'))",
                    [$d['id']],
                ), 'id'));
                $current = array_map('intval', array_column($this->db->fetchAll(
                    'SELECT user_id FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL',
                    [$cid],
                ), 'user_id'));
                foreach (array_diff($expected, $current) as $uid) {
                    $this->enter($cid, $uid, $this->chat->event($cid, 'member_added', null, $uid));
                    $count['added']++;
                }
                foreach (array_diff($current, $expected) as $uid) {
                    $this->chat->event($cid, 'member_left', null, $uid);
                    $this->db->run('UPDATE conversation_members SET left_at = UTC_TIMESTAMP() WHERE conversation_id = ? AND user_id = ?', [$cid, $uid]);
                    $count['removed']++;
                }
            });
        }
        return $count;
    }

    /** Arrivée dans un salon : tout l'historique, mais rien de non lu (position = $eventId). */
    private function enter(int $cid, int $userId, int $eventId): void
    {
        $this->db->run(
            'INSERT INTO conversation_members (conversation_id, user_id, joined_event_id, entered_event_id, left_at, notify, last_read_event_id)
             VALUES (?, ?, 0, ?, NULL, 0, ?) ON DUPLICATE KEY UPDATE joined_event_id = 0, entered_event_id = VALUES(entered_event_id),
             left_at = NULL, notify = 0, last_read_event_id = VALUES(last_read_event_id), last_read_at = NULL',
            [$cid, $userId, $eventId, $eventId],
        );
    }

    private function setArchived(int $cid, bool $archived, ?int $actorId): void
    {
        $this->db->transaction(function () use ($cid, $archived, $actorId) {
            $this->db->update('conversations', ['archived_at' => $archived ? now_utc() : null], ['id' => $cid]);
            $this->chat->event($cid, $archived ? 'archived' : 'unarchived', $actorId);
        });
    }

    /** @return array<string,mixed> */
    private function channel(int $cid, string $type): array
    {
        $c = $this->db->fetch('SELECT * FROM conversations WHERE id = ?', [$cid]);
        if ($c === null || $c['type'] !== $type) {
            throw new HttpException(404);
        }
        return $c;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function manageable(array $actor, int $cid): array
    {
        $c = $this->db->fetch('SELECT * FROM conversations WHERE id = ?', [$cid]) ?? throw new HttpException(404);
        if ($c['type'] === 'department') {
            throw new ValidationException(['name' => self::SERVICE_SALON]);
        }
        if ($c['type'] !== 'channel') {
            throw new HttpException(404);
        }
        if (!$this->canManage($actor, $c)) {
            throw new HttpException(403);
        }
        return $c;
    }

    /** @return array{0:string,1:?string} */
    private function valid(string $name, string $description, ?int $self, bool $skipUnique = false): array
    {
        $name = trim($name);
        $description = trim($description);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ValidationException(['name' => 'Le nom du salon doit compter de 1 à 80 caractères.']);
        }
        if (mb_strlen($description) > 200) {
            throw new ValidationException(['description' => 'La description ne doit pas dépasser 200 caractères.']);
        }
        $taken = !$skipUnique && $this->db->value(
            "SELECT 1 FROM conversations WHERE type = 'channel' AND archived_at IS NULL AND name = ? AND id <> ?",
            [$name, $self ?? 0],
        ) !== null;
        if ($taken) {
            throw new ValidationException(['name' => 'Un salon porte déjà ce nom.']);
        }
        return [$name, $description !== '' ? $description : null];
    }
}
