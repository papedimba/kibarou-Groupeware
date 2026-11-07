<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Modules\Auth\PasswordPolicy;
use App\Modules\Auth\PasswordResetService;
use App\Services\AuditLogger;
use App\Services\Rbac;

/**
 * Règles métier de gestion des comptes.
 * Garde-fous : seul un super administrateur peut attribuer le rôle super_admin ou modifier
 * un super administrateur ; on ne peut pas se désactiver soi-même ; il reste toujours
 * au moins un super administrateur actif.
 */
final class UserService
{
    private const RULES = [
        'email' => 'required|email|max:190',
        'first_name' => 'required|max:100',
        'last_name' => 'required|max:100',
        'phone' => 'phone|max:30',
        'department_id' => 'int',
        'position_id' => 'int',
        'manager_id' => 'int',
    ];
    private const LABELS = [
        'email' => 'Email', 'first_name' => 'Prénom', 'last_name' => 'Nom', 'phone' => 'Téléphone',
        'department_id' => 'Service', 'position_id' => 'Fonction', 'manager_id' => 'Responsable',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly Rbac $rbac,
        private readonly AuditLogger $audit,
        private readonly PasswordResetService $resets,
        private readonly int $passwordMinLength,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Crée un compte. Sans mot de passe temporaire, un lien d'activation est envoyé par email.
     * @param array<string,mixed> $input
     * @param list<int> $roleIds
     * @param array<string,mixed> $actor
     */
    public function create(array $input, array $roleIds, ?string $tempPassword, array $actor): int
    {
        $data = $this->validate($input, 0);
        $this->guardRoles($roleIds, $actor);
        if ($tempPassword !== null && $tempPassword !== '') {
            $policy = PasswordPolicy::check($tempPassword, $this->passwordMinLength, (string) $data['email']);
            if ($policy !== null) {
                throw new ValidationException(['temp_password' => $policy]);
            }
        }
        $id = $this->db->transaction(function () use ($data, $roleIds, $tempPassword, $actor) {
            $hasTemp = $tempPassword !== null && $tempPassword !== '';
            $id = $this->db->insert('users', [
                ...$data,
                // Sans mot de passe temporaire : hash d'une valeur aléatoire inconnue de tous.
                'password_hash' => PasswordPolicy::hash($hasTemp ? $tempPassword : bin2hex(random_bytes(32))),
                'status' => 'active',
                'must_change_password' => $hasTemp ? 1 : 0,
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
            $this->users->syncRoles($id, $roleIds);
            $this->audit->log((int) $actor['id'], 'user.created', 'user', $id, ['email' => $data['email'], 'roles' => $roleIds]);
            if (!$hasTemp) {
                $this->resets->invite($id, $this->appUrl);
            }
            return $id;
        });
        $this->rbac->flush();
        return $id;
    }

    /**
     * @param array<string,mixed> $input
     * @param list<int> $roleIds
     * @param array<string,mixed> $actor
     */
    public function update(int $id, array $input, array $roleIds, array $actor): void
    {
        $target = $this->users->find($id) ?? throw new HttpException(404);
        $this->guardTarget($target, $actor);
        $data = $this->validate($input, $id);
        if (isset($data['manager_id']) && (int) $data['manager_id'] === $id) {
            throw new ValidationException(['manager_id' => 'Un utilisateur ne peut pas être son propre responsable.']);
        }
        $this->guardRoles($roleIds, $actor);
        $wasSuper = in_array(Rbac::SUPER_ADMIN, $this->users->roleSlugs($id), true);
        if ($wasSuper && !in_array($this->superAdminRoleId(), $roleIds, true) && $this->users->countActiveSuperAdmins($id) === 0) {
            throw new ValidationException(['roles' => 'Impossible de retirer le dernier super administrateur.']);
        }
        if ($id === (int) $actor['id'] && !$this->keepsAdminAccess($roleIds)) {
            throw new ValidationException(['roles' => 'Vous ne pouvez pas retirer vos propres droits d\'administration.']);
        }
        $this->db->transaction(function () use ($id, $data, $roleIds, $actor, $target) {
            $this->db->update('users', [...$data, 'updated_at' => now_utc()], ['id' => $id]);
            $oldRoles = $this->users->roleIds($id);
            sort($oldRoles);
            sort($roleIds);
            $this->users->syncRoles($id, $roleIds);
            $this->audit->log((int) $actor['id'], 'user.updated', 'user', $id, [
                'changes' => array_keys(array_filter($data, static fn ($v, $k) => (string) $v !== (string) ($target[$k] ?? ''), ARRAY_FILTER_USE_BOTH)),
                'roles_before' => $oldRoles,
                'roles_after' => $roleIds,
            ]);
            if ($oldRoles !== $roleIds) {
                // Changement de droits : les sessions ouvertes de l'utilisateur sont fermées.
                $this->db->run('DELETE FROM sessions WHERE user_id = ? AND user_id <> ?', [$id, $actor['id']]);
            }
        });
        $this->rbac->flush();
    }

    /** @param array<string,mixed> $actor */
    public function setStatus(int $id, bool $active, array $actor): void
    {
        $target = $this->users->find($id) ?? throw new HttpException(404);
        $this->guardTarget($target, $actor);
        if (!$active) {
            if ($id === (int) $actor['id']) {
                throw new ValidationException(['status' => 'Vous ne pouvez pas désactiver votre propre compte.']);
            }
            if (in_array(Rbac::SUPER_ADMIN, $this->users->roleSlugs($id), true) && $this->users->countActiveSuperAdmins($id) === 0) {
                throw new ValidationException(['status' => 'Impossible de désactiver le dernier super administrateur.']);
            }
        }
        $this->db->transaction(function () use ($id, $active, $actor) {
            $this->db->update('users', ['status' => $active ? 'active' : 'disabled', 'updated_at' => now_utc()], ['id' => $id]);
            if (!$active) {
                $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$id]);
            }
            $this->audit->log((int) $actor['id'], $active ? 'user.enabled' : 'user.disabled', 'user', $id);
        });
    }

    /** Envoie un lien de réinitialisation à l'utilisateur (action d'administrateur). @param array<string,mixed> $actor */
    public function sendResetLink(int $id, array $actor): void
    {
        $target = $this->users->find($id) ?? throw new HttpException(404);
        $this->guardTarget($target, $actor);
        $this->resets->invite($id, $this->appUrl);
        $this->audit->log((int) $actor['id'], 'user.reset_link_sent', 'user', $id);
    }

    /** @param array<string,mixed> $actor */
    public function revokeSessions(int $id, array $actor, ?string $sessionId = null): int
    {
        $target = $this->users->find($id) ?? throw new HttpException(404);
        $this->guardTarget($target, $actor);
        $count = $sessionId === null
            ? $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$id])->rowCount()
            : $this->db->run('DELETE FROM sessions WHERE user_id = ? AND id = ?', [$id, $sessionId])->rowCount();
        $this->audit->log((int) $actor['id'], 'user.sessions_revoked', 'user', $id, ['count' => $count]);
        return $count;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, int $id): array
    {
        $data = Validator::validate($input, self::RULES, self::LABELS);
        $data['email'] = mb_strtolower((string) $data['email']);
        $errors = [];
        if ($this->users->emailTaken($data['email'], $id)) {
            $errors['email'] = 'Cette adresse email est déjà utilisée.';
        }
        foreach (['department_id' => 'departments', 'position_id' => 'positions', 'manager_id' => 'users'] as $field => $table) {
            $data[$field] = $data[$field] !== null ? (int) $data[$field] : null;
            if ($data[$field] !== null && $this->db->value("SELECT 1 FROM `$table` WHERE id = ?", [$data[$field]]) === null) {
                $errors[$field] = 'Valeur inexistante.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $data;
    }

    /** @param list<int> $roleIds @param array<string,mixed> $actor */
    private function guardRoles(array $roleIds, array $actor): void
    {
        $known = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM roles'), 'id'));
        if (array_diff($roleIds, $known) !== []) {
            throw new ValidationException(['roles' => 'Rôle inconnu.']);
        }
        if ($roleIds === []) {
            throw new ValidationException(['roles' => 'Sélectionnez au moins un rôle.']);
        }
        if (in_array($this->superAdminRoleId(), $roleIds, true) && !$this->rbac->hasRole($actor, Rbac::SUPER_ADMIN)) {
            throw new HttpException(403, 'Seul un super administrateur peut attribuer ce rôle.');
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $actor */
    private function guardTarget(array $target, array $actor): void
    {
        if (in_array(Rbac::SUPER_ADMIN, $this->users->roleSlugs((int) $target['id']), true) && !$this->rbac->hasRole($actor, Rbac::SUPER_ADMIN)) {
            throw new HttpException(403, 'Seul un super administrateur peut modifier ce compte.');
        }
    }

    /** @param list<int> $roleIds */
    private function keepsAdminAccess(array $roleIds): bool
    {
        if (in_array($this->superAdminRoleId(), $roleIds, true)) {
            return true;
        }
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        return $this->db->value(
            "SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
             WHERE p.slug = 'users.manage' AND rp.role_id IN ($placeholders) LIMIT 1",
            $roleIds,
        ) !== null;
    }

    private function superAdminRoleId(): int
    {
        return (int) $this->db->value('SELECT id FROM roles WHERE slug = ?', [Rbac::SUPER_ADMIN]);
    }
}
