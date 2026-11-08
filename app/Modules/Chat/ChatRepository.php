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
