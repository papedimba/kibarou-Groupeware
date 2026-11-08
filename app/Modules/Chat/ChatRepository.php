<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Core\Database;

/** Lecture de la messagerie, toujours filtrée sur l'appartenance active et la période d'appartenance. */
final class ChatRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function membership(int $userId, int $conversationId): ?array
    {
        return $this->db->fetch(
            'SELECT cm.*, c.type, c.name FROM conversation_members cm JOIN conversations c ON c.id = cm.conversation_id
             WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL',
            [$conversationId, $userId],
        );
    }

    private const EVENT_COLUMNS = "e.id, e.conversation_id, e.type, e.created_at, e.actor_id, e.target_user_id,
        m.sender_id, m.body, CONCAT(a.first_name, ' ', a.last_name) AS actor_name, CONCAT(t.first_name, ' ', t.last_name) AS target_name";

    private const EVENT_JOINS = "LEFT JOIN messages m ON m.id = e.message_id
        LEFT JOIN users a ON a.id = e.actor_id
        LEFT JOIN users t ON t.id = e.target_user_id";

    /**
     * Évènements de mes conversations actives, postérieurs à $since et à mon arrivée.
     * @return list<array<string,mixed>>
     */
    public function events(int $userId, int $since, int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT ' . self::EVENT_COLUMNS . ' FROM chat_events e
             JOIN conversation_members cm ON cm.conversation_id = e.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL
             ' . self::EVENT_JOINS . "
             WHERE e.id > ? AND e.id > cm.joined_event_id AND e.type <> 'created'
             ORDER BY e.id LIMIT " . max(1, min(500, $limit)),
            [$userId, $since],
        );
    }

    /**
     * Historique d'une conversation (du plus ancien au plus récent), avant $beforeEventId.
     * @return list<array<string,mixed>>
     */
    public function history(int $userId, int $cid, ?int $beforeEventId, int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::EVENT_COLUMNS . ' FROM chat_events e
             JOIN conversation_members cm ON cm.conversation_id = e.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL
             ' . self::EVENT_JOINS . "
             WHERE e.conversation_id = ? AND e.id > cm.joined_event_id AND e.type <> 'created' AND e.id < ?
             ORDER BY e.id DESC LIMIT " . max(1, min(200, $limit)),
            [$userId, $cid, $beforeEventId ?? PHP_INT_MAX],
        );
        return array_reverse($rows);
    }

    /** Messages d'autrui non lus, postérieurs à mon arrivée. */
    private const UNREAD = "(SELECT COUNT(*) FROM chat_events ue WHERE ue.conversation_id = cm.conversation_id AND ue.type = 'message'
        AND ue.id > cm.last_read_event_id AND ue.id > cm.joined_event_id AND (ue.actor_id IS NULL OR ue.actor_id <> cm.user_id))";

    /**
     * Mes conversations actives, la plus récemment active d'abord.
     * @return list<array<string,mixed>>
     */
    public function conversationsFor(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.type, c.name, c.last_event_at, " . self::UNREAD . " AS unread,
                    (SELECT LEFT(m.body, 80) FROM chat_events le JOIN messages m ON m.id = le.message_id
                     WHERE le.conversation_id = c.id AND le.type = 'message' AND le.id > cm.joined_event_id ORDER BY le.id DESC LIMIT 1) AS last_body,
                    o.id AS other_id, o.first_name AS other_first_name, o.last_name AS other_last_name, o.avatar_path AS other_avatar_path
             FROM conversation_members cm
             JOIN conversations c ON c.id = cm.conversation_id
             LEFT JOIN conversation_members om ON c.type = 'direct' AND om.conversation_id = c.id AND om.user_id <> cm.user_id
             LEFT JOIN users o ON o.id = om.user_id
             WHERE cm.user_id = ? AND cm.left_at IS NULL
             ORDER BY c.last_event_at DESC, c.id DESC",
            [$userId],
        );
        return array_map(static function ($r) {
            $r['unread'] = (int) $r['unread'];
            $r['title'] = $r['type'] === 'group' ? (string) $r['name'] : trim($r['other_first_name'] . ' ' . $r['other_last_name']);
            return $r;
        }, $rows);
    }

    /** @return array<int,int> non-lus par conversation active */
    public function unreadByConversation(int $userId): array
    {
        $rows = $this->db->fetchAll('SELECT cm.conversation_id, ' . self::UNREAD . ' AS unread FROM conversation_members cm WHERE cm.user_id = ? AND cm.left_at IS NULL', [$userId]);
        return array_combine(array_map('intval', array_column($rows, 'conversation_id')), array_map('intval', array_column($rows, 'unread'))) ?: [];
    }

    public function unreadTotal(int $userId): int
    {
        return (int) $this->db->value('SELECT COALESCE(SUM(' . self::UNREAD . '), 0) FROM conversation_members cm WHERE cm.user_id = ? AND cm.left_at IS NULL', [$userId]);
    }

    /** @return list<array<string,mixed>> membres actifs */
    public function members(int $conversationId): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.first_name, u.last_name, u.avatar_path, u.status, cm.last_read_at
             FROM conversation_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.conversation_id = ? AND cm.left_at IS NULL ORDER BY u.last_name, u.first_name",
            [$conversationId],
        );
    }
}
