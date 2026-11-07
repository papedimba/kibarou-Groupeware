<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Contrôle d'accès par rôles. Les permissions sont calculées côté serveur, en une requête
 * par utilisateur et par requête HTTP. Le rôle `super_admin` possède toutes les permissions.
 */
final class Rbac
{
    public const SUPER_ADMIN = 'super_admin';

    /** @var array<int,array{roles:list<string>,permissions:array<string,true>}> */
    private array $cache = [];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $user */
    public function can(array $user, string $permission): bool
    {
        $data = $this->load((int) $user['id']);
        return in_array(self::SUPER_ADMIN, $data['roles'], true) || isset($data['permissions'][$permission]);
    }

    /** @param array<string,mixed> $user */
    public function hasRole(array $user, string $role): bool
    {
        return in_array($role, $this->load((int) $user['id'])['roles'], true);
    }

    /**
     * @param array<string,mixed> $user
     * @return list<string>
     */
    public function roles(array $user): array
    {
        return $this->load((int) $user['id'])['roles'];
    }

    /** @return array{roles:list<string>,permissions:array<string,true>} */
    private function load(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }
        $rows = $this->db->fetchAll(
            'SELECT r.slug AS role, p.slug AS permission
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?',
            [$userId],
        );
        $roles = [];
        $perms = [];
        foreach ($rows as $row) {
            $roles[(string) $row['role']] = true;
            if ($row['permission'] !== null) {
                $perms[(string) $row['permission']] = true;
            }
        }
        return $this->cache[$userId] = ['roles' => array_keys($roles), 'permissions' => $perms];
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
