<?php

declare(strict_types=1);

namespace App\Modules\Directory;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Organization\OrganizationService;

final class DirectoryController extends Controller
{
    public function index(Request $request): Response
    {
        $q = mb_substr($request->str('q'), 0, 100);
        $departmentId = $request->int('department_id');
        $members = $this->repo()->members($q, $departmentId);
        return $this->view('directory/index', [
            'currentPath' => '/directory',
            'members' => $members,
            'statuses' => $this->app->presence()->current(array_map(static fn ($m) => (int) $m['id'], $members)),
            'online' => $this->app->presence()->onlineIds(),
            'q' => $q,
            'departmentId' => $departmentId,
            'departments' => (new OrganizationService($this->app->db(), $this->app->audit()))->departmentOptions(),
        ]);
    }

    public function org(Request $request): Response
    {
        $tree = $this->repo()->orgTree();
        $without = $this->repo()->withoutDepartment();
        return $this->view('directory/org', [
            'currentPath' => '/directory',
            'tree' => $tree,
            'without' => $without,
            'statuses' => $this->app->presence()->current(array_map(static fn ($m) => (int) $m['id'], $this->repo()->members())),
            'myDepartment' => (int) ($this->user()['department_id'] ?? 0),
        ]);
    }

    public function show(Request $request): Response
    {
        $person = $this->repo()->find($this->id($request)) ?? throw new HttpException(404);
        return $this->view('directory/show', [
            'currentPath' => '/directory',
            'person' => $person,
            'showPersonal' => $this->canSeePersonalPhone($this->user(), $person),
            'status' => $this->app->presence()->current([(int) $person['id']])[(int) $person['id']] ?? null,
            'online' => isset($this->app->presence()->onlineIds()[(int) $person['id']]),
            'canEditStatus' => $this->app->presence()->canEdit($this->user(), (int) $person['id']),
            'chatEnabled' => (int) $this->app->db()->value("SELECT is_enabled FROM modules WHERE slug = 'chat'") === 1,
        ]);
    }

    /** Numéro personnel : la personne, son responsable (hiérarchique ou de service) et les administrateurs. */
    private function canSeePersonalPhone(array $viewer, array $person): bool
    {
        return (int) $viewer['id'] === (int) $person['id']
            || $this->app->rbac()->can($viewer, 'users.manage')
            || $this->repo()->isResponsable((int) $viewer['id'], (int) $person['id']);
    }

    private function repo(): DirectoryRepository
    {
        return new DirectoryRepository($this->app->db());
    }
}
