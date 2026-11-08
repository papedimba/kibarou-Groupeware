<?php

declare(strict_types=1);

namespace App\Modules\Directory;

use App\Core\Database;

/** Lecture de l'annuaire : collaborateurs actifs et règle du « responsable ». */
final class DirectoryRepository
{
    private const COLUMNS = "u.id, u.first_name, u.last_name, u.email, u.phone, u.phone_personal, u.avatar_path,
        u.department_id, d.name AS department_name, p.title AS position_title, u.manager_id";

    private const JOINS = "LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN positions p ON p.id = u.position_id";

    private const ACTIVE = "u.status = 'active' AND u.deleted_at IS NULL";

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function members(string $q = '', int $departmentId = 0): array
    {
        $where = [self::ACTIVE];
        $params = [];
        if ($q !== '') {
            // utf8mb4_unicode_ci : comparaison insensible aux accents et à la casse.
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR CONCAT(u.last_name, ' ', u.first_name) LIKE ?
                OR p.title LIKE ? OR d.name LIKE ?
                OR EXISTS (SELECT 1 FROM user_presence up WHERE up.user_id = u.id AND up.site_label LIKE ?))";
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($departmentId > 0) {
            $where[] = 'u.department_id = ?';
            $params[] = $departmentId;
        }
        return $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM users u ' . self::JOINS . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.last_name, u.first_name',
            $params,
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT ' . self::COLUMNS . ", CONCAT(m.first_name, ' ', m.last_name) AS manager_name, d.manager_user_id AS department_manager_id
             FROM users u " . self::JOINS . " LEFT JOIN users m ON m.id = u.manager_id AND m.status = 'active' AND m.deleted_at IS NULL
             WHERE u.id = ? AND " . self::ACTIVE,
            [$id],
        );
    }

    /**
     * Arbre des services actifs : responsable en tête (non répété parmi les membres), membres, sous-services.
     * Un service sans responsable, sans membre et sans sous-service affiché est omis.
     * @return list<array<string,mixed>>
     */
    public function orgTree(): array
    {
        $departments = $this->db->fetchAll('SELECT id, name, parent_id, manager_user_id FROM departments WHERE is_active = 1 ORDER BY name');
        $people = [];
        foreach ($this->members() as $m) {
            $people[(int) $m['id']] = $m;
        }
        $nodes = [];
        foreach ($departments as $d) {
            $head = $d['manager_user_id'] !== null ? ($people[(int) $d['manager_user_id']] ?? null) : null;
            $nodes[(int) $d['id']] = ['id' => (int) $d['id'], 'name' => $d['name'], 'parent_id' => $d['parent_id'], 'manager' => $head, 'members' => [], 'children' => []];
        }
        foreach ($people as $p) {
            $dept = $p['department_id'] !== null ? (int) $p['department_id'] : null;
            if ($dept !== null && isset($nodes[$dept]) && (int) $p['id'] !== (int) ($nodes[$dept]['manager']['id'] ?? 0)) {
                $nodes[$dept]['members'][] = $p;
            }
        }
        $build = function (?int $parent) use (&$build, $nodes): array {
            $out = [];
            foreach ($nodes as $n) {
                // Parent inactif ou absent : le service remonte à la racine.
                $nodeParent = $n['parent_id'] !== null && isset($nodes[(int) $n['parent_id']]) ? (int) $n['parent_id'] : null;
                if ($nodeParent !== $parent) {
                    continue;
                }
                $n['children'] = $build($n['id']);
                if ($n['manager'] !== null || $n['members'] !== [] || $n['children'] !== []) {
                    $out[] = $n;
                }
            }
            return $out;
        };
        return $build(null);
    }

    /** @return list<array<string,mixed>> collaborateurs actifs sans service (ou rattachés à un service inactif) */
    public function withoutDepartment(): array
    {
        return array_values(array_filter($this->members(), static fn ($m) => $m['department_id'] === null || $m['department_name'] === null));
    }

    /** Responsable de P : son responsable hiérarchique direct, ou le responsable de son service. */
    public function isResponsable(int $actorId, int $targetId): bool
    {
        return $actorId !== $targetId && $this->db->value(
            'SELECT 1 FROM users u LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.id = ? AND (u.manager_id = ? OR d.manager_user_id = ?)',
            [$targetId, $actorId, $actorId],
        ) !== null;
    }
}
