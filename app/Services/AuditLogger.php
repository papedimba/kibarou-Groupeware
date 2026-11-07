<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Journal d'audit des actions sensibles (table audit_logs). */
final class AuditLogger
{
    private ?string $ip = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function setIp(string $ip): void
    {
        $this->ip = $ip;
    }

    /** @param array<string,mixed> $details Ne jamais y mettre de mot de passe ni de jeton. */
    public function log(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
    {
        $this->db->insert('audit_logs', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $this->ip,
            'details' => $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'created_at' => now_utc(),
        ]);
    }
}
