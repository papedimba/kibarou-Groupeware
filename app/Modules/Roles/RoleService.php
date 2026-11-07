<?php

declare(strict_types=1);

namespace App\Modules\Roles;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\AuditLogger;
use App\Services\Rbac;

/** Rôles personnalisables. Le rôle super_admin n'est pas modifiable (toutes permissions implicites). */
final class RoleService
{
    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
             FROM roles r ORDER BY r.id',
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    /** @return array<string,list<array<string,mixed>>> permissions groupées par module */
    public function permissionsByModule(): array
    {
        $grouped = [];
        foreach ($this->db->fetchAll('SELECT * FROM permissions ORDER BY module, slug') as $p) {
            $grouped[(string) $p['module']][] = $p;
        }
        return $grouped;
    }

    /** @return list<int> */
    public function permissionIds(int $roleId): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]),
            'permission_id',
        ));
    }

    /**
     * @param array<string,mixed> $input
     * @param list<int> $permissionIds
     * @param array<string,mixed> $actor
     */
    public function save(?int $id, array $input, array $permissionIds, array $actor): int
    {
        $role = $id !== null ? ($this->find($id) ?? throw new HttpException(404)) : null;
        if ($role !== null && $role['slug'] === Rbac::SUPER_ADMIN) {
            throw new HttpException(403, 'Le rôle super administrateur ne peut pas être modifié.');
        }
        $rules = ['name' => 'required|max:100', 'description' => 'max:500'];
        if ($role === null) {
            $rules['slug'] = 'required|slug';
        }
        $data = Validator::validate($input, $rules, ['name' => 'Nom', 'description' => 'Description', 'slug' => 'Identifiant']);
        if ($role === null && $this->db->value('SELECT 1 FROM roles WHERE slug = ?', [$data['slug']]) !== null) {
            throw new ValidationException(['slug' => 'Cet identifiant existe déjà.']);
        }
        $known = array_map('intval', array_column($this->db->fetchAll('SELECT id FROM permissions'), 'id'));
        if (array_diff($permissionIds, $known) !== []) {
            throw new ValidationException(['permissions' => 'Permission inconnue.']);
        }
        return $this->db->transaction(function () use ($id, $data, $permissionIds, $actor) {
            if ($id === null) {
                $id = $this->db->insert('roles', [...$data, 'is_system' => 0, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            } else {
                $this->db->update('roles', [...$data, 'updated_at' => now_utc()], ['id' => $id]);
            }
            $before = $this->permissionIds($id);
            $this->db->run('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($permissionIds as $pid) {
                $this->db->insert('role_permissions', ['role_id' => $id, 'permission_id' => $pid]);
            }
            $this->audit->log((int) $actor['id'], 'role.saved', 'role', $id, ['permissions_before' => $before, 'permissions_after' => $permissionIds]);
            return $id;
        });
    }

    /** @param array<string,mixed> $actor */
    public function delete(int $id, array $actor): void
    {
        $role = $this->find($id) ?? throw new HttpException(404);
        if ((int) $role['is_system'] === 1) {
            throw new ValidationException(['role' => 'Les rôles système ne peuvent pas être supprimés.']);
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$id]) > 0) {
            throw new ValidationException(['role' => 'Ce rôle est encore attribué à des utilisateurs.']);
        }
        $this->db->run('DELETE FROM roles WHERE id = ?', [$id]);
        $this->audit->log((int) $actor['id'], 'role.deleted', 'role', $id, ['name' => $role['name']]);
    }
}
