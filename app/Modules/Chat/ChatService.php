<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Modules\Notifications\Notifier;
use App\Services\AuditLogger;
use App\Services\Rbac;
use PDOException;

/**
 * Règles de la messagerie. Confidentialité stricte : chaque action vérifie l'appartenance active.
 * Toute écriture visible passe par le journal chat_events (curseur de l'interrogation).
 */
final class ChatService
{
    public const MAX_LENGTH = 4000;
    private const MAX_PER_MINUTE = 30;
    private const MAX_MEMBERS = 51;

    public function __construct(
        private readonly Database $db,
        private readonly ChatRepository $repo,
        private readonly Rbac $rbac,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {
    }

    /** Conversation privée avec $otherId (créée au besoin, unique par paire). @param array<string,mixed> $actor */
    public function directWith(array $actor, int $otherId): int
    {
        $me = (int) $actor['id'];
        $other = $this->activeChatUser($otherId);
        if ($other === null || $otherId === $me) {
            throw new HttpException(404);
        }
        $key = min($me, $otherId) . '-' . max($me, $otherId);
        $existing = $this->db->value('SELECT id FROM conversations WHERE direct_key = ?', [$key]);
        if ($existing !== null) {
            return (int) $existing;
        }
        try {
            return $this->db->transaction(function () use ($me, $otherId, $key) {
                $cid = $this->db->insert('conversations', [
                    'type' => 'direct', 'direct_key' => $key, 'created_by' => $me, 'created_at' => now_utc(), 'last_event_at' => now_utc(),
                ]);
                $created = $this->event($cid, 'created', $me);
                foreach ([$me, $otherId] as $uid) {
                    $this->db->insert('conversation_members', ['conversation_id' => $cid, 'user_id' => $uid, 'joined_event_id' => $created]);
                }
                return $cid;
            });
        } catch (PDOException $e) {
            // Création simultanée par l'autre personne : la clé unique a tranché, on relit.
            $id = $this->db->value('SELECT id FROM conversations WHERE direct_key = ?', [$key]);
            if ($id === null) {
                throw $e;
            }
            return (int) $id;
        }
    }

    /**
     * @param array<string,mixed> $actor
     * @return array{event_id:int,message_id:int}
     */
    public function send(array $actor, int $conversationId, string $body): array
    {
        $me = (int) $actor['id'];
        $membership = $this->requireMember($me, $conversationId);
        $body = rtrim($body);
        if (trim($body) === '') {
            throw new ValidationException(['body' => 'Le message est vide.']);
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new ValidationException(['body' => 'Le message ne doit pas dépasser ' . self::MAX_LENGTH . ' caractères.']);
        }
        if ($membership['type'] === 'direct') {
            $other = $this->db->value('SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id <> ?', [$conversationId, $me]);
            if ($other === null || $this->activeChatUser((int) $other) === null) {
                throw new ValidationException(['body' => 'Cette personne ne fait plus partie de l\'intranet : elle ne peut plus recevoir de messages.']);
            }
        }
        $recent = (int) $this->db->value('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE', [$me]);
        if ($recent >= self::MAX_PER_MINUTE) {
            throw new HttpException(429, 'Trop de messages en peu de temps. Patientez une minute.');
        }
        $result = $this->db->transaction(function () use ($me, $conversationId, $body) {
            $messageId = $this->db->insert('messages', ['conversation_id' => $conversationId, 'sender_id' => $me, 'body' => $body, 'created_at' => now_utc()]);
            $eventId = $this->event($conversationId, 'message', $me, null, $messageId);
            return ['event_id' => $eventId, 'message_id' => $messageId];
        });
        $this->notifyMembers($actor, $membership);
        return $result;
    }

    /** Marque lu jusqu'à $eventId (jamais à la baisse) et lit les notifications de cette conversation. @param array<string,mixed> $actor */
    public function markRead(array $actor, int $cid, int $eventId): void
    {
        $me = (int) $actor['id'];
        $this->requireMember($me, $cid);
        $max = (int) $this->db->value('SELECT COALESCE(MAX(id), 0) FROM chat_events WHERE conversation_id = ?', [$cid]);
        $this->db->run(
            'UPDATE conversation_members SET last_read_event_id = GREATEST(last_read_event_id, ?), last_read_at = UTC_TIMESTAMP()
             WHERE conversation_id = ? AND user_id = ?',
            [min($eventId, $max), $cid, $me],
        );
        $this->db->run(
            "UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND type = 'chat.message' AND url = ? AND read_at IS NULL",
            [$me, '/chat/' . $cid],
        );
    }

    /**
     * Une notification par conversation tant qu'elle n'est pas lue ; aucune si la conversation vient d'être lue.
     * Jamais d'extrait du message (écran verrouillé, boîte mail partagée).
     * @param array<string,mixed> $actor @param array<string,mixed> $membership
     */
    private function notifyMembers(array $actor, array $membership): void
    {
        $cid = (int) $membership['conversation_id'];
        $url = '/chat/' . $cid;
        $title = 'Nouveau message de ' . $actor['first_name'] . ' ' . $actor['last_name']
            . ($membership['type'] === 'group' ? ' dans ' . $membership['name'] : '');
        $recipients = $this->db->fetchAll(
            "SELECT cm.user_id FROM conversation_members cm
             WHERE cm.conversation_id = ? AND cm.user_id <> ? AND cm.left_at IS NULL
               AND (cm.last_read_at IS NULL OR cm.last_read_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE)
               AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.user_id = cm.user_id AND n.type = 'chat.message' AND n.url = ? AND n.read_at IS NULL)",
            [$cid, $actor['id'], $url],
        );
        foreach ($recipients as $r) {
            $this->notifier->notify((int) $r['user_id'], 'chat.message', $title, $url, null, (int) $actor['id']);
        }
    }

    /**
     * @param array<string,mixed> $actor
     * @param list<int> $memberIds autres membres (2 à 50)
     */
    public function createGroup(array $actor, string $name, array $memberIds): int
    {
        $me = (int) $actor['id'];
        $name = $this->validName($name);
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn ($id) => $id !== $me)));
        if (count($ids) < 2 || count($ids) > self::MAX_MEMBERS - 1) {
            throw new ValidationException(['members' => 'Un groupe réunit de 2 à ' . (self::MAX_MEMBERS - 1) . ' autres personnes.']);
        }
        $this->requireChatUsers($ids);
        $cid = $this->db->transaction(function () use ($me, $name, $ids) {
            $cid = $this->db->insert('conversations', ['type' => 'group', 'name' => $name, 'created_by' => $me, 'created_at' => now_utc(), 'last_event_at' => now_utc()]);
            $created = $this->event($cid, 'created', $me);
            $this->db->insert('conversation_members', ['conversation_id' => $cid, 'user_id' => $me, 'joined_event_id' => $created]);
            foreach ($ids as $uid) {
                $this->db->insert('conversation_members', ['conversation_id' => $cid, 'user_id' => $uid, 'joined_event_id' => $created]);
                $this->event($cid, 'member_added', $me, $uid);
            }
            return $cid;
        });
        $this->audit->log($me, 'chat.group_created', 'conversation', $cid, ['count' => count($ids) + 1]);
        return $cid;
    }

    /** @param array<string,mixed> $actor @param list<int> $userIds */
    public function addMembers(array $actor, int $cid, array $userIds): void
    {
        $me = (int) $actor['id'];
        $this->requireGroup($me, $cid);
        $active = array_map('intval', array_column($this->repo->members($cid), 'id'));
        $ids = array_values(array_diff(array_unique(array_map('intval', $userIds)), $active));
        if ($ids === []) {
            return;
        }
        if (count($active) + count($ids) > self::MAX_MEMBERS) {
            throw new ValidationException(['members' => 'Un groupe compte au plus ' . self::MAX_MEMBERS . ' membres.']);
        }
        $this->requireChatUsers($ids);
        $this->db->transaction(function () use ($me, $cid, $ids) {
            foreach ($ids as $uid) {
                // Le nouveau membre voit son ajout et la suite, jamais ce qui précède.
                $before = (int) $this->db->value('SELECT COALESCE(MAX(id), 0) FROM chat_events WHERE conversation_id = ?', [$cid]);
                $this->event($cid, 'member_added', $me, $uid);
                $this->db->run(
                    'INSERT INTO conversation_members (conversation_id, user_id, joined_event_id, left_at, last_read_event_id)
                     VALUES (?, ?, ?, NULL, ?) ON DUPLICATE KEY UPDATE joined_event_id = VALUES(joined_event_id), left_at = NULL, last_read_event_id = VALUES(last_read_event_id), last_read_at = NULL',
                    [$cid, $uid, $before, $before],
                );
                $this->audit->log($me, 'chat.member_added', 'conversation', $cid);
            }
        });
    }

    /** @param array<string,mixed> $actor */
    public function leave(array $actor, int $cid): void
    {
        $me = (int) $actor['id'];
        $this->requireGroup($me, $cid);
        $this->db->transaction(function () use ($me, $cid) {
            $this->event($cid, 'member_left', $me, $me);
            $this->db->run('UPDATE conversation_members SET left_at = UTC_TIMESTAMP() WHERE conversation_id = ? AND user_id = ?', [$cid, $me]);
        });
        $this->audit->log($me, 'chat.member_left', 'conversation', $cid);
    }

    /** @param array<string,mixed> $actor */
    public function rename(array $actor, int $cid, string $name): void
    {
        $me = (int) $actor['id'];
        $this->requireGroup($me, $cid);
        $name = $this->validName($name);
        $this->db->transaction(function () use ($me, $cid, $name) {
            $this->db->update('conversations', ['name' => $name], ['id' => $cid]);
            $this->event($cid, 'renamed', $me);
        });
    }

    /** @return array<string,mixed> appartenance active, sinon 404 (un identifiant n'est jamais une preuve) */
    public function requireMember(int $userId, int $conversationId): array
    {
        return $this->repo->membership($userId, $conversationId) ?? throw new HttpException(404);
    }

    /** Ajoute un évènement et met à jour la date de dernière activité de la conversation. */
    private function event(int $conversationId, string $type, ?int $actorId, ?int $targetId = null, ?int $messageId = null): int
    {
        $id = $this->db->insert('chat_events', [
            'conversation_id' => $conversationId, 'type' => $type, 'message_id' => $messageId,
            'actor_id' => $actorId, 'target_user_id' => $targetId, 'created_at' => now_utc(),
        ]);
        $this->db->run('UPDATE conversations SET last_event_at = UTC_TIMESTAMP() WHERE id = ?', [$conversationId]);
        return $id;
    }

    /** @return array<string,mixed> */
    private function requireGroup(int $userId, int $cid): array
    {
        $m = $this->requireMember($userId, $cid);
        if ($m['type'] !== 'group') {
            throw new ValidationException(['members' => 'Action réservée aux groupes.']);
        }
        return $m;
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ValidationException(['name' => 'Le nom du groupe doit compter de 1 à 80 caractères.']);
        }
        return $name;
    }

    /** @param list<int> $ids */
    private function requireChatUsers(array $ids): void
    {
        foreach ($ids as $id) {
            if ($this->activeChatUser($id) === null) {
                throw new ValidationException(['members' => 'Une des personnes choisies ne peut pas utiliser la messagerie.']);
            }
        }
    }

    /** @return array<string,mixed>|null utilisateur actif autorisé à utiliser la messagerie */
    private function activeChatUser(int $userId): ?array
    {
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$userId]);
        return $user !== null && $this->rbac->can($user, 'chat.use') ? $user : null;
    }
}
