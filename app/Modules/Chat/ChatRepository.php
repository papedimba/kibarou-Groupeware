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
