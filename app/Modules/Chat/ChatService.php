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
    private const MAX_FILES_PER_MINUTE = 30;
    public const EDIT_WINDOW_SECONDS = 900;
    public const REACTIONS = ['👍', '✅', '👀', '🙏', '❤️', '😂'];

    public function __construct(
        private readonly Database $db,
        private readonly ChatRepository $repo,
        private readonly Rbac $rbac,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly ChatAttachmentService $attachments,
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
                    $this->db->insert('conversation_members', ['conversation_id' => $cid, 'user_id' => $uid, 'joined_event_id' => $created, 'entered_event_id' => $created]);
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
     * Envoie un message. $clientId (32 hexadécimaux, créé par le navigateur) rend l'envoi rejouable sans doublon ;
     * $attachmentIds : pièces déjà envoyées par cet expéditeur dans cette conversation, non rattachées.
     * @param array<string,mixed> $actor
     * @param list<int|string> $attachmentIds
     * @return array{event_id:int,message_id:int}
     */
    public function send(array $actor, int $conversationId, string $body, ?string $clientId = null, array $attachmentIds = [], ?int $replyToId = null): array
    {
        $me = (int) $actor['id'];
        $membership = $this->requireMember($me, $conversationId);
        self::requireWritable($membership);
        if ($clientId !== null && $clientId !== '') {
            if (!preg_match('/^[a-f0-9]{32}$/', $clientId)) {
                throw new ValidationException(['body' => 'Identifiant de message invalide.']);
            }
            $existing = $this->existingByClientId($me, $clientId);
            if ($existing !== null) {
                return $existing;
            }
        } else {
            $clientId = null;
        }
        $ids = array_values(array_unique(array_map('intval', $attachmentIds)));
        if (count($ids) > ChatAttachmentService::MAX_PER_MESSAGE) {
            throw new ValidationException(['attachments' => 'Dix pièces jointes au plus par message.']);
        }
        $body = rtrim($body);
        if (trim($body) === '' && $ids === []) {
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
        if ($replyToId !== null && $this->repo->visibleMessage($me, $replyToId, $conversationId) === null) {
            throw new ValidationException(['reply_to' => 'Le message cité est introuvable.']);
        }
        $recent = (int) $this->db->value('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE', [$me]);
        if ($recent >= self::MAX_PER_MINUTE) {
            throw new HttpException(429, 'Trop de messages en peu de temps. Patientez une minute.');
        }
        try {
            $result = $this->db->transaction(function () use ($me, $conversationId, $body, $clientId, $ids, $replyToId) {
                if ($ids !== []) {
                    // Verrou : une même pièce ne peut pas être rattachée par deux envois simultanés.
                    $mine = $this->db->fetchAll(
                        'SELECT id FROM chat_attachments WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                         AND uploader_id = ? AND conversation_id = ? AND message_id IS NULL FOR UPDATE',
                        [...$ids, $me, $conversationId],
                    );
                    if (count($mine) !== count($ids)) {
                        throw new ValidationException(['attachments' => 'Une pièce jointe est introuvable ou déjà envoyée.']);
                    }
                }
                $messageId = $this->db->insert('messages', ['conversation_id' => $conversationId, 'sender_id' => $me, 'body' => $body,
                    'client_id' => $clientId, 'reply_to_id' => $replyToId, 'created_at' => now_utc()]);
                if ($ids !== []) {
                    $this->db->run('UPDATE chat_attachments SET message_id = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$messageId, ...$ids]);
                }
                $eventId = $this->event($conversationId, 'message', $me, null, $messageId);
                return ['event_id' => $eventId, 'message_id' => $messageId];
            });
        } catch (PDOException $e) {
            // Deux envois simultanés du même client_id : la clé unique a tranché.
            $existing = $clientId !== null ? $this->existingByClientId($me, $clientId) : null;
            if ($existing === null) {
                throw $e;
            }
            return $existing;
        }
        $this->notifyMembers($actor, $membership);
        return $result;
    }

    /**
     * Pièce jointe envoyée avant le message (une requête par fichier, sous la limite de l'hébergement).
     * @param array<string,mixed> $actor @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public function uploadAttachment(array $actor, int $conversationId, array $file): array
    {
        $me = (int) $actor['id'];
        self::requireWritable($this->requireMember($me, $conversationId));
        $recent = (int) $this->db->value('SELECT COUNT(*) FROM chat_attachments WHERE uploader_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE', [$me]);
        if ($recent >= self::MAX_FILES_PER_MINUTE) {
            throw new HttpException(429, 'Trop de fichiers en peu de temps. Patientez une minute.');
        }
        return $this->attachments->store($me, $conversationId, $file);
    }

    /**
     * Pièce jointe lisible par $userId : membre actif, message visible (postérieur à son arrivée) et non supprimé ;
     * l'expéditeur voit aussi ses pièces pas encore envoyées (aperçu). Sinon 404.
     * @return array<string,mixed>
     */
    public function attachmentFor(int $userId, int $attachmentId): array
    {
        return $this->db->fetch(
            "SELECT a.* FROM chat_attachments a
             JOIN conversation_members cm ON cm.conversation_id = a.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL
             LEFT JOIN messages m ON m.id = a.message_id
             LEFT JOIN chat_events e ON e.message_id = m.id AND e.type = 'message'
             WHERE a.id = ? AND ((a.message_id IS NULL AND a.uploader_id = ?) OR (m.deleted_at IS NULL AND e.id > cm.joined_event_id))",
            [$userId, $attachmentId, $userId],
        ) ?? throw new HttpException(404);
    }

    /** Corrige le texte d'un de ses messages, dans les 15 minutes. @param array<string,mixed> $actor */
    public function edit(array $actor, int $messageId, string $body): int
    {
        $message = $this->ownRecentMessage($actor, $messageId, 'Ce message ne peut plus être modifié.');
        $body = rtrim($body);
        if (trim($body) === '') {
            throw new ValidationException(['body' => 'Le message est vide.']);
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new ValidationException(['body' => 'Le message ne doit pas dépasser ' . self::MAX_LENGTH . ' caractères.']);
        }
        return $this->db->transaction(function () use ($message, $body, $actor) {
            $this->db->update('messages', ['body' => $body, 'edited_at' => now_utc()], ['id' => $message['id']]);
            return $this->event((int) $message['conversation_id'], 'message_edited', (int) $actor['id'], null, (int) $message['id'], null, false);
        });
    }

    /** Retire un de ses messages, dans les 15 minutes : texte vidé, fichiers effacés du disque. @param array<string,mixed> $actor */
    public function delete(array $actor, int $messageId): int
    {
        $message = $this->ownRecentMessage($actor, $messageId, 'Ce message ne peut plus être supprimé.');
        $files = $this->db->fetchAll('SELECT * FROM chat_attachments WHERE message_id = ?', [$message['id']]);
        $eventId = $this->db->transaction(function () use ($message, $actor) {
            $this->db->update('messages', ['body' => '', 'deleted_at' => now_utc()], ['id' => $message['id']]);
            $this->db->run('DELETE FROM chat_attachments WHERE message_id = ?', [$message['id']]);
            $this->db->run('DELETE FROM chat_reactions WHERE message_id = ?', [$message['id']]);
            return $this->event((int) $message['conversation_id'], 'message_deleted', (int) $actor['id'], null, (int) $message['id'], null, false);
        });
        // Après validation : un échec de la transaction ne doit pas avoir effacé les fichiers.
        $this->attachments->deleteFiles($files);
        $this->audit->log((int) $actor['id'], 'chat.message_deleted', 'conversation', (int) $message['conversation_id']);
        return $eventId;
    }

    /** Ajoute ou retire une réaction (liste fixe), sans notification. @param array<string,mixed> $actor */
    public function react(array $actor, int $messageId, string $emoji): int
    {
        $me = (int) $actor['id'];
        $message = $this->repo->visibleMessage($me, $messageId) ?? throw new HttpException(404);
        if ($message['deleted_at'] !== null) {
            throw new HttpException(404);
        }
        self::requireWritable($this->requireMember($me, (int) $message['conversation_id']));
        if (!in_array($emoji, self::REACTIONS, true)) {
            throw new ValidationException(['emoji' => 'Réaction inconnue.']);
        }
        return $this->db->transaction(function () use ($me, $message, $emoji) {
            $removed = $this->db->run('DELETE FROM chat_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?', [$message['id'], $me, $emoji])->rowCount();
            if ($removed === 0) {
                $this->db->insert('chat_reactions', ['message_id' => $message['id'], 'user_id' => $me, 'emoji' => $emoji, 'created_at' => now_utc()]);
            }
            return $this->event((int) $message['conversation_id'], 'reaction', $me, null, (int) $message['id'], null, false);
        });
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function ownRecentMessage(array $actor, int $messageId, string $refusal): array
    {
        $me = (int) $actor['id'];
        $message = $this->repo->visibleMessage($me, $messageId) ?? throw new HttpException(404);
        self::requireWritable($this->requireMember($me, (int) $message['conversation_id']));
        $tooOld = strtotime($message['created_at'] . ' UTC') < time() - self::EDIT_WINDOW_SECONDS;
        if ((int) $message['sender_id'] !== $me || $message['deleted_at'] !== null || $tooOld) {
            throw new ValidationException(['body' => $refusal]);
        }
        return $message;
    }

    /** @return array{event_id:int,message_id:int}|null */
    private function existingByClientId(int $senderId, string $clientId): ?array
    {
        $row = $this->db->fetch(
            "SELECT m.id AS message_id, e.id AS event_id FROM messages m JOIN chat_events e ON e.message_id = m.id AND e.type = 'message'
             WHERE m.sender_id = ? AND m.client_id = ?",
            [$senderId, $clientId],
        );
        return $row !== null ? ['event_id' => (int) $row['event_id'], 'message_id' => (int) $row['message_id']] : null;
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
            . ($membership['type'] !== 'direct' ? ' dans ' . $membership['name'] : '');
        $recipients = $this->db->fetchAll(
            "SELECT cm.user_id FROM conversation_members cm
             WHERE cm.conversation_id = ? AND cm.user_id <> ? AND cm.left_at IS NULL AND cm.notify = 1
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
            $this->db->insert('conversation_members', ['conversation_id' => $cid, 'user_id' => $me, 'joined_event_id' => $created, 'entered_event_id' => $created]);
            foreach ($ids as $uid) {
                $this->db->insert('conversation_members', ['conversation_id' => $cid, 'user_id' => $uid, 'joined_event_id' => $created, 'entered_event_id' => $created]);
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
                    'INSERT INTO conversation_members (conversation_id, user_id, joined_event_id, entered_event_id, left_at, last_read_event_id)
                     VALUES (?, ?, ?, ?, NULL, ?) ON DUPLICATE KEY UPDATE joined_event_id = VALUES(joined_event_id), entered_event_id = VALUES(entered_event_id),
                     left_at = NULL, last_read_event_id = VALUES(last_read_event_id), last_read_at = NULL',
                    [$cid, $uid, $before, $before, $before],
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
            $this->event($cid, 'renamed', $me, null, null, $name);
        });
    }

    /** @return array<string,mixed> appartenance active, sinon 404 (un identifiant n'est jamais une preuve) */
    public function requireMember(int $userId, int $conversationId): array
    {
        return $this->repo->membership($userId, $conversationId) ?? throw new HttpException(404);
    }

    /** Ajoute un évènement et met à jour la date de dernière activité de la conversation (aussi utilisé par ChannelService). */
    public function event(int $conversationId, string $type, ?int $actorId, ?int $targetId = null, ?int $messageId = null, ?string $detail = null, bool $touch = true): int
    {
        $id = $this->db->insert('chat_events', [
            'conversation_id' => $conversationId, 'type' => $type, 'message_id' => $messageId,
            'actor_id' => $actorId, 'target_user_id' => $targetId, 'detail' => $detail, 'created_at' => now_utc(),
        ]);
        // Une modification, une suppression ou une réaction ne fait pas remonter la conversation.
        if ($touch) {
            $this->db->run('UPDATE conversations SET last_event_at = UTC_TIMESTAMP() WHERE id = ?', [$conversationId]);
        }
        return $id;
    }

    /** Salon archivé : lecture seule. @param array<string,mixed> $membership */
    public static function requireWritable(array $membership): void
    {
        if (!empty($membership['archived_at'])) {
            throw new ValidationException(['body' => 'Ce salon est archivé : il n\'accepte plus de messages.']);
        }
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
