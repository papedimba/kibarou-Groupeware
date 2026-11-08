<?php

declare(strict_types=1);

namespace App\Modules\Organization;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\AuditLogger;

/** Services (départements) et fonctions (postes). */
final class OrganizationService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        // Salons de service tenus à jour après chaque modification de service (absent : cron:run s'en charge).
        private readonly ?\App\Modules\Chat\ChannelService $channels = null,
    )
    {
    }

    /** @return list<array<string,mixed>> */
    public function departments(): array
    {
        return $this->db->fetchAll(
            "SELECT d.*, p.name AS parent_name, CONCAT(m.first_name, ' ', m.last_name) AS manager_name,
                    (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.deleted_at IS NULL) AS user_count
             FROM departments d
             LEFT JOIN departments p ON p.id = d.parent_id
             LEFT JOIN users m ON m.id = d.manager_user_id
             ORDER BY d.name",
        );
    }

    /** @return array<string,mixed>|null */
    public function department(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM departments WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function saveDepartment(?int $id, array $input, array $actor): int
    {
        if ($id !== null && $this->department($id) === null) {
            throw new HttpException(404);
        }
        $data = Validator::validate($input, [
            'name' => 'required|max:150',
            'code' => 'max:30',
            'description' => 'max:500',
            'parent_id' => 'int',
            'manager_user_id' => 'int',
            'is_active' => 'in:0,1',
        ], ['name' => 'Nom', 'code' => 'Code', 'description' => 'Description', 'parent_id' => 'Service parent', 'manager_user_id' => 'Responsable']);
        $data['parent_id'] = $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
        $data['manager_user_id'] = $data['manager_user_id'] !== null ? (int) $data['manager_user_id'] : null;
        $data['is_active'] = (int) ($data['is_active'] ?? 1);
        $this->guardUnique('departments', 'name', (string) $data['name'], $id, 'Un service porte déjà ce nom.');
        if ($data['code'] !== null) {
            $this->guardUnique('departments', 'code', (string) $data['code'], $id, 'Ce code est déjà utilisé.');
        }
        if ($data['parent_id'] !== null) {
            if ($this->department($data['parent_id']) === null) {
                throw new ValidationException(['parent_id' => 'Service parent inexistant.']);
            }
            if ($id !== null && $this->createsCycle($id, $data['parent_id'])) {
                throw new ValidationException(['parent_id' => 'Un service ne peut pas dépendre de lui-même ou de l\'un de ses sous-services.']);
            }
        }
        if ($data['manager_user_id'] !== null && $this->db->value('SELECT 1 FROM users WHERE id = ? AND deleted_at IS NULL', [$data['manager_user_id']]) === null) {
            throw new ValidationException(['manager_user_id' => 'Responsable inexistant.']);
        }
        $saved = $this->persist('departments', 'department', $id, $data, $actor);
        $this->channels?->syncDepartments();
        return $saved;
    }

    /** @return list<array<string,mixed>> */
    public function positions(): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, (SELECT COUNT(*) FROM users u WHERE u.position_id = p.id AND u.deleted_at IS NULL) AS user_count
             FROM positions p ORDER BY p.title',
        );
    }

    /** @return array<string,mixed>|null */
    public function position(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM positions WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function savePosition(?int $id, array $input, array $actor): int
    {
        if ($id !== null && $this->position($id) === null) {
            throw new HttpException(404);
        }
        $data = Validator::validate($input, [
            'title' => 'required|max:150',
            'description' => 'max:500',
            'is_active' => 'in:0,1',
        ], ['title' => 'Intitulé', 'description' => 'Description']);
        $data['is_active'] = (int) ($data['is_active'] ?? 1);
        $this->guardUnique('positions', 'title', (string) $data['title'], $id, 'Cette fonction existe déjà.');
        return $this->persist('positions', 'position', $id, $data, $actor);
    }

    /** @return list<array{id:int,name:string}> */
    public function departmentOptions(): array
    {
        return $this->db->fetchAll('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name');
    }

    /** @return list<array{id:int,title:string}> */
    public function positionOptions(): array
    {
        return $this->db->fetchAll('SELECT id, title FROM positions WHERE is_active = 1 ORDER BY title');
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $actor */
    private function persist(string $table, string $entity, ?int $id, array $data, array $actor): int
    {
        $data['updated_at'] = now_utc();
        if ($id === null) {
            $id = $this->db->insert($table, [...$data, 'created_at' => now_utc()]);
            $this->audit->log((int) $actor['id'], "$entity.created", $entity, $id, ['name' => $data['name'] ?? $data['title'] ?? null]);
        } else {
            $this->db->update($table, $data, ['id' => $id]);
            $this->audit->log((int) $actor['id'], "$entity.updated", $entity, $id);
        }
        return $id;
    }

    private function guardUnique(string $table, string $column, string $value, ?int $id, string $message): void
    {
        if ($this->db->value("SELECT 1 FROM `$table` WHERE `$column` = ? AND id <> ?", [$value, $id ?? 0]) !== null) {
            throw new ValidationException([$column => $message]);
        }
    }

    /** Vrai si rattacher $id sous $parentId créerait une boucle dans la hiérarchie. */
    private function createsCycle(int $id, int $parentId): bool
    {
        $seen = [];
        for ($current = $parentId; $current !== null; ) {
            if ($current === $id || isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $next = $this->db->value('SELECT parent_id FROM departments WHERE id = ?', [$current]);
            $current = $next !== null ? (int) $next : null;
        }
        return false;
    }
}
