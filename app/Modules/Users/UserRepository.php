<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Database;
use App\Core\Paginator;

final class UserRepository
{
    public const PER_PAGE = 25;

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array{q?:string,status?:string,department_id?:int} $filters */
    public function paginate(array $filters, int $page): Paginator
    {
        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
            $like = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if (in_array($filters['status'] ?? '', ['active', 'disabled'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $filters['status'];
        }
        if (($filters['department_id'] ?? 0) > 0) {
            $where[] = 'u.department_id = ?';
            $params[] = $filters['department_id'];
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM users u WHERE $sqlWhere", $params);
        $items = $this->db->fetchAll(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.status, u.last_login_at,
                    d.name AS department_name, p.title AS position_title,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ', ')
                     FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_names
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN positions p ON p.id = u.position_id
             WHERE $sqlWhere
             ORDER BY u.last_name, u.first_name
             LIMIT " . self::PER_PAGE . ' OFFSET ' . Paginator::offset($page, self::PER_PAGE),
            $params,
        );
        return new Paginator($items, $total, max(1, $page), self::PER_PAGE);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public function emailTaken(string $email, int $exceptId = 0): bool
    {
        return $this->db->value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $exceptId]) !== null;
    }

    /** @return list<int> */
    public function roleIds(int $userId): array
    {
        return array_map('intval', array_column($this->db->fetchAll('SELECT role_id FROM user_roles WHERE user_id = ?', [$userId]), 'role_id'));
    }

    /** @return list<string> */
    public function roleSlugs(int $userId): array
    {
        return array_column($this->db->fetchAll(
            'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
            [$userId],
        ), 'slug');
    }

    /** @param list<int> $roleIds */
    public function syncRoles(int $userId, array $roleIds): void
    {
        $this->db->run('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        foreach ($roleIds as $roleId) {
            $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    /** Nombre de super administrateurs actifs, éventuellement hors d'un utilisateur donné. */
    public function countActiveSuperAdmins(int $exceptUserId = 0): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'super_admin' AND u.status = 'active' AND u.deleted_at IS NULL AND u.id <> ?",
            [$exceptUserId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function sessions(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT id, ip_address, user_agent, created_at, last_activity_at FROM sessions WHERE user_id = ? ORDER BY last_activity_at DESC',
            [$userId],
        );
    }

    /** @return list<array{id:int,name:string}> utilisateurs actifs pour les listes de sélection */
    public function options(): array
    {
        return $this->db->fetchAll(
            "SELECT id, CONCAT(last_name, ' ', first_name) AS name FROM users
             WHERE status = 'active' AND deleted_at IS NULL ORDER BY last_name, first_name",
        );
    }
}
