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

    private const EVENT_COLUMNS = "e.id, e.conversation_id, e.type, e.created_at, e.actor_id, e.target_user_id, e.detail,
        e.message_id, m.sender_id, m.body, m.client_id, m.reply_to_id, m.edited_at, m.deleted_at, CONCAT(a.first_name, ' ', a.last_name) AS actor_name, CONCAT(t.first_name, ' ', t.last_name) AS target_name";

    private const ACTIONS = "'message_edited', 'message_deleted', 'reaction'";
    /** Une modification, suppression ou réaction n'est livrée que si le message visé est lui-même visible (postérieur à l'arrivée). */
    private const ORIGIN_VISIBLE = "(e.type NOT IN ('message_edited', 'message_deleted', 'reaction') OR EXISTS (SELECT 1 FROM chat_events oe
        WHERE oe.message_id = e.message_id AND oe.type = 'message' AND oe.id > cm.joined_event_id))";

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
             WHERE e.id > ? AND e.id > cm.joined_event_id AND e.type <> 'created' AND " . self::ORIGIN_VISIBLE . "
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
             WHERE e.conversation_id = ? AND e.id > cm.joined_event_id AND e.type NOT IN ('created', " . self::ACTIONS . ") AND e.id < ?
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
    public function conversationsFor(int $userId, int $openId = 0): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.type, c.name, c.last_event_at, " . self::UNREAD . " AS unread,
                    (SELECT le.message_id FROM chat_events le
                     WHERE le.conversation_id = c.id AND le.type = 'message' AND le.id > cm.joined_event_id ORDER BY le.id DESC LIMIT 1) AS last_message_id,
                    o.id AS other_id, o.first_name AS other_first_name, o.last_name AS other_last_name, o.avatar_path AS other_avatar_path
             FROM conversation_members cm
             JOIN conversations c ON c.id = cm.conversation_id
             LEFT JOIN conversation_members om ON c.type = 'direct' AND om.conversation_id = c.id AND om.user_id <> cm.user_id
             LEFT JOIN users o ON o.id = om.user_id
             WHERE cm.user_id = ? AND cm.left_at IS NULL
               -- Une conversation privée ouverte sans message n'apparaît que chez celui qui l'a ouverte.
               AND (c.type = 'group' OR c.created_by = cm.user_id OR c.id = ?
                    OR EXISTS (SELECT 1 FROM chat_events x WHERE x.conversation_id = c.id AND x.type = 'message'))
             ORDER BY c.last_event_at DESC, c.id DESC",
            [$userId, $openId],
        );
        $lastIds = array_values(array_filter(array_map(static fn ($r) => (int) $r['last_message_id'], $rows)));
        $last = $lastIds === [] ? [] : array_column($this->db->fetchAll('SELECT id, body, deleted_at FROM messages WHERE id IN ('
            . implode(',', array_fill(0, count($lastIds), '?')) . ')', $lastIds), null, 'id');
        $files = $this->attachmentsByMessage($lastIds);
        return array_map(static function ($r) use ($last, $files) {
            $m = $last[(int) $r['last_message_id']] ?? null;
            $r['last_body'] = match (true) {
                $m === null => null,
                $m['deleted_at'] !== null => 'Message supprimé',
                default => self::excerpt((string) $m['body'], $files[(int) $m['id']] ?? []),
            };
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

    /**
     * Message visible par $userId : membre actif et message postérieur à son arrivée.
     * @return array<string,mixed>|null message + conversation_id + event_id d'origine
     */
    public function visibleMessage(int $userId, int $messageId, ?int $conversationId = null): ?array
    {
        $row = $this->db->fetch(
            "SELECT m.*, e.id AS event_id FROM messages m
             JOIN chat_events e ON e.message_id = m.id AND e.type = 'message'
             JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL
             WHERE m.id = ? AND e.id > cm.joined_event_id",
            [$userId, $messageId],
        );
        return $row !== null && ($conversationId === null || (int) $row['conversation_id'] === $conversationId) ? $row : null;
    }

    /**
     * Ajoute aux évènements « message » ce que l'affichage demande : pièces jointes, réactions, extrait cité.
     * Une requête par type de donnée pour tout le lot. L'extrait d'un message antérieur à l'arrivée du lecteur est masqué.
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    public function hydrate(int $userId, array $events): array
    {
        $ids = array_values(array_unique(array_map(static fn ($e) => (int) $e['message_id'], array_filter($events, static fn ($e) => $e['message_id'] !== null))));
        $replyIds = array_values(array_unique(array_map(static fn ($e) => (int) $e['reply_to_id'], array_filter($events, static fn ($e) => !empty($e['reply_to_id'])))));
        $attachments = $this->attachmentsByMessage([...$ids, ...$replyIds]);
        $reactions = $this->reactionsByMessage($userId, $ids);
        $replies = [];
        if ($replyIds !== []) {
            $joined = array_column($this->db->fetchAll('SELECT conversation_id, joined_event_id FROM conversation_members WHERE user_id = ? AND left_at IS NULL', [$userId]), 'joined_event_id', 'conversation_id');
            $rows = $this->db->fetchAll(
                "SELECT m.id, m.conversation_id, m.body, m.deleted_at, e.id AS event_id, CONCAT(u.first_name, ' ', u.last_name) AS sender_name
                 FROM messages m JOIN chat_events e ON e.message_id = m.id AND e.type = 'message' LEFT JOIN users u ON u.id = m.sender_id
                 WHERE m.id IN (" . implode(',', array_fill(0, count($replyIds), '?')) . ')',
                $replyIds,
            );
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $replies[$id] = match (true) {
                    (int) $r['event_id'] <= (int) ($joined[$r['conversation_id']] ?? PHP_INT_MAX) => ['id' => $id, 'hidden' => true],
                    $r['deleted_at'] !== null => ['id' => $id, 'deleted' => true],
                    default => ['id' => $id, 'sender_name' => $r['sender_name'], 'excerpt' => self::excerpt((string) $r['body'], $attachments[$id] ?? [])],
                };
            }
        }
        foreach ($events as &$e) {
            $mid = $e['message_id'] !== null ? (int) $e['message_id'] : null;
            $e['deleted'] = !empty($e['deleted_at']);
            $e['attachments'] = $mid !== null && !$e['deleted'] ? ($attachments[$mid] ?? []) : [];
            $e['reactions'] = $mid !== null && !$e['deleted'] ? ($reactions[$mid] ?? []) : [];
            $e['reply_to'] = !empty($e['reply_to_id']) ? ($replies[(int) $e['reply_to_id']] ?? ['id' => (int) $e['reply_to_id'], 'deleted' => true]) : null;
        }
        unset($e);
        return $events;
    }

    /** Extrait d'un message : texte sur une ligne (80 caractères), sinon « Photo » / « Fichier : nom ». @param list<array<string,mixed>> $attachments */
    public static function excerpt(string $body, array $attachments): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $body));
        if ($text !== '') {
            return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
        }
        $first = $attachments[0] ?? null;
        return match (true) {
            $first === null => '',
            $first['kind'] === 'image' => 'Photo',
            default => 'Fichier : ' . $first['name'],
        };
    }

    /** @param list<int> $ids @return array<int,list<array<string,mixed>>> */
    private function attachmentsByMessage(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll('SELECT id, message_id, kind, original_name, size, width, height FROM chat_attachments WHERE message_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids) as $a) {
            $out[(int) $a['message_id']][] = ['id' => (int) $a['id'], 'kind' => $a['kind'], 'name' => $a['original_name'], 'size' => (int) $a['size'],
                'width' => $a['width'] !== null ? (int) $a['width'] : null, 'height' => $a['height'] !== null ? (int) $a['height'] : null];
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int,list<array{emoji:string,count:int,mine:bool,names:list<string>}>> */
    private function reactionsByMessage(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll("SELECT r.message_id, r.emoji, r.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name
            FROM chat_reactions r JOIN users u ON u.id = r.user_id WHERE r.message_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY r.created_at, r.user_id', $ids) as $r) {
            $slot = &$out[(int) $r['message_id']][$r['emoji']];
            $slot ??= ['emoji' => $r['emoji'], 'count' => 0, 'mine' => false, 'names' => []];
            $slot['count']++;
            $slot['mine'] = $slot['mine'] || (int) $r['user_id'] === $userId;
            $slot['names'][] = $r['name'];
            unset($slot);
        }
        return array_map('array_values', $out);
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
