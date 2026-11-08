<?php

declare(strict_types=1);

namespace App\Modules\Directory;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Organization\OrganizationService;

final class DirectoryController extends Controller
{
    public function index(Request $request): Response
    {
        $q = mb_substr($request->str('q'), 0, 100);
        $departmentId = $request->int('department_id');
        return $this->view('directory/index', [
            'currentPath' => '/directory',
            'members' => $this->repo()->members($q, $departmentId),
            'q' => $q,
            'departmentId' => $departmentId,
            'departments' => (new OrganizationService($this->app->db(), $this->app->audit()))->departmentOptions(),
        ]);
    }

    private function repo(): DirectoryRepository
    {
        return new DirectoryRepository($this->app->db());
    }
}
