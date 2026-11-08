<?php

declare(strict_types=1);

namespace App\Modules\Directory;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\AuditLogger;
use App\Services\Rbac;

/**
 * Statut déclaré (où se trouve la personne aujourd'hui) et présence en ligne.
 * Un statut vaut du jour de sa déclaration jusqu'à COALESCE(until_date, start_date) inclus,
 * « aujourd'hui » étant la date dans le fuseau de l'entreprise.
 */
final class PresenceService
{
    public const LABELS = [
        'office' => 'Au bureau',
        'site' => 'Sur chantier',
        'travel' => 'En déplacement',
        'leave' => 'En congé',
        'absent' => 'Absent',
    ];
    private const ONLINE_MINUTES = 5;
    private const MAX_DAYS = 90;

    public function __construct(
        private readonly Database $db,
        private readonly DirectoryRepository $repo,
        private readonly Rbac $rbac,
        private readonly AuditLogger $audit,
        private readonly string $timezone,
    ) {
    }

    public function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('Y-m-d');
    }

    /**
     * Statuts valides aujourd'hui, indexés par utilisateur.
     * @param list<int> $userIds
     * @return array<int,array{status:string,label:string,site_label:?string,note:?string,until_date:?string}>
     */
    public function current(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT user_id, status, site_label, note, until_date FROM user_presence
             WHERE user_id IN ($in) AND start_date <= ? AND COALESCE(until_date, start_date) >= ?",
            [...array_map('intval', $userIds), $this->today(), $this->today()],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = [
                'status' => (string) $r['status'],
                'label' => self::LABELS[$r['status']],
                'site_label' => $r['site_label'],
                'note' => $r['note'],
                'until_date' => $r['until_date'],
            ];
        }
        return $out;
    }

    /** @return array<int,true> utilisateurs actifs dans les 5 dernières minutes */
    public function onlineIds(): array
    {
        $ids = $this->db->fetchAll(
            'SELECT DISTINCT user_id FROM sessions WHERE user_id IS NOT NULL AND last_activity_at > UTC_TIMESTAMP() - INTERVAL ' . self::ONLINE_MINUTES . ' MINUTE',
        );
        return array_fill_keys(array_map('intval', array_column($ids, 'user_id')), true);
    }

    /** @param array<string,mixed> $actor */
    public function canEdit(array $actor, int $targetId): bool
    {
        return (int) $actor['id'] === $targetId
            || $this->rbac->can($actor, 'users.manage')
            || $this->repo->isResponsable((int) $actor['id'], $targetId);
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $input
     */
    public function set(array $actor, int $targetId, array $input): void
    {
        $this->guard($actor, $targetId);
        $this->write($actor, $targetId, $this->validate($input));
    }

    /**
     * Personnes actives dont l'acteur est le responsable (§5.0), avec leur statut du jour.
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public function team(array $actor): array
    {
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.first_name, u.last_name FROM users u LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.status = 'active' AND u.deleted_at IS NULL AND u.id <> ? AND (u.manager_id = ? OR d.manager_user_id = ?)
             ORDER BY u.last_name, u.first_name",
            [$actor['id'], $actor['id'], $actor['id']],
        );
        $statuses = $this->current(array_map(static fn ($r) => (int) $r['id'], $rows));
        return array_map(static fn ($r) => [...$r, 'presence' => $statuses[(int) $r['id']] ?? null], $rows);
    }

    /**
     * Même statut pour plusieurs personnes. Tout ou rien : un seul identifiant non modifiable refuse l'ensemble.
     * @param array<string,mixed> $actor
     * @param list<int> $targetIds
     * @param array<string,mixed> $input
     */
    public function bulkSet(array $actor, array $targetIds, array $input): int
    {
        if ($targetIds === []) {
            throw new ValidationException(['user_ids' => 'Cochez au moins une personne.']);
        }
        foreach ($targetIds as $id) {
            $this->guard($actor, $id);
        }
        $data = $this->validate($input);
        $this->db->transaction(function () use ($actor, $targetIds, $data) {
            foreach ($targetIds as $id) {
                $this->write($actor, $id, $data);
            }
        });
        return count($targetIds);
    }

    /** @param array<string,mixed> $actor */
    public function clear(array $actor, int $targetId): void
    {
        $this->guard($actor, $targetId);
        $this->db->run('DELETE FROM user_presence WHERE user_id = ?', [$targetId]);
        if ((int) $actor['id'] !== $targetId) {
            $this->audit->log((int) $actor['id'], 'presence.updated', 'user', $targetId, ['status' => 'effacé']);
        }
    }

    /** @param array<string,mixed> $actor */
    private function guard(array $actor, int $targetId): void
    {
        if ($this->repo->find($targetId) === null) {
            throw new HttpException(404);
        }
        if (!$this->canEdit($actor, $targetId)) {
            throw new HttpException(403);
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{status:string,site_label:?string,note:?string,until_date:?string}
     */
    private function validate(array $input): array
    {
        $data = Validator::validate($input, [
            'status' => 'required|in:' . implode(',', array_keys(self::LABELS)),
            'site_label' => 'max:120',
            'note' => 'max:160',
            'until_date' => 'date',
        ], ['status' => 'Statut', 'site_label' => 'Chantier', 'note' => 'Note', 'until_date' => 'Jusqu\'au']);
        $status = (string) $data['status'];
        if ($status === 'site' && $data['site_label'] === null) {
            throw new ValidationException(['site_label' => 'Indiquez le nom du chantier.']);
        }
        $until = $data['until_date'] !== null ? (string) $data['until_date'] : null;
        if ($until !== null) {
            $max = (new \DateTimeImmutable($this->today()))->modify('+' . self::MAX_DAYS . ' days')->format('Y-m-d');
            if ($until < $this->today() || $until > $max) {
                throw new ValidationException(['until_date' => 'La date de fin doit être comprise entre aujourd\'hui et dans ' . self::MAX_DAYS . ' jours.']);
            }
        }
        // Note et date de fin ne s'affichent que pour chantier, déplacement et congé : ignorées sinon.
        $details = in_array($status, ['site', 'travel', 'leave'], true);
        return [
            'status' => $status,
            'site_label' => $status === 'site' ? (string) $data['site_label'] : null,
            'note' => $details && $data['note'] !== null ? (string) $data['note'] : null,
            'until_date' => $details ? $until : null,
        ];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array{status:string,site_label:?string,note:?string,until_date:?string} $data
     */
    private function write(array $actor, int $targetId, array $data): void
    {
        $this->db->run(
            'REPLACE INTO user_presence (user_id, status, site_label, note, start_date, until_date, set_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$targetId, $data['status'], $data['site_label'], $data['note'], $this->today(), $data['until_date'], (int) $actor['id']],
        );
        if ((int) $actor['id'] !== $targetId) {
            $this->audit->log((int) $actor['id'], 'presence.updated', 'user', $targetId, ['status' => self::LABELS[$data['status']]]);
        }
    }
}
