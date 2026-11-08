<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
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

    public function __construct(
        private readonly Database $db,
        private readonly ChatRepository $repo,
        private readonly Rbac $rbac,
        private readonly AuditLogger $audit,
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
        return $this->db->transaction(function () use ($me, $conversationId, $body) {
            $messageId = $this->db->insert('messages', ['conversation_id' => $conversationId, 'sender_id' => $me, 'body' => $body, 'created_at' => now_utc()]);
            $eventId = $this->event($conversationId, 'message', $me, null, $messageId);
            return ['event_id' => $eventId, 'message_id' => $messageId];
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

    /** @return array<string,mixed>|null utilisateur actif autorisé à utiliser la messagerie */
    private function activeChatUser(int $userId): ?array
    {
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$userId]);
        return $user !== null && $this->rbac->can($user, 'chat.use') ? $user : null;
    }
}
