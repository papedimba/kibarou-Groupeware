<?php

declare(strict_types=1);

namespace App\Modules\Organization;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Modules\Users\UserRepository;

final class OrganizationController extends Controller
{
    public function departments(Request $request): Response
    {
        return $this->view('admin/organization/departments', [
            'currentPath' => '/admin/departments',
            'departments' => $this->service()->departments(),
        ]);
    }

    public function departmentForm(Request $request): Response
    {
        $id = $this->id($request);
        $dept = $id > 0 ? ($this->service()->department($id) ?? throw new HttpException(404)) : null;
        return $this->view('admin/organization/department_form', [
            'currentPath' => '/admin/departments',
            'dept' => $dept,
            'parents' => array_values(array_filter($this->service()->departmentOptions(), static fn ($d) => (int) $d['id'] !== $id)),
            'managers' => (new UserRepository($this->app->db()))->options(),
        ]);
    }

    public function saveDepartment(Request $request): Response
    {
        $id = $this->id($request) ?: null;
        try {
            $this->service()->saveDepartment($id, $request->post, $this->user());
        } catch (ValidationException $e) {
            return $this->back($id ? "/admin/departments/$id/edit" : '/admin/departments/create', $e->errors, $request->post);
        }
        return $this->redirect('/admin/departments', 'Service enregistré.');
    }

    public function positions(Request $request): Response
    {
        return $this->view('admin/organization/positions', [
            'currentPath' => '/admin/positions',
            'positions' => $this->service()->positions(),
        ]);
    }

    public function positionForm(Request $request): Response
    {
        $id = $this->id($request);
        return $this->view('admin/organization/position_form', [
            'currentPath' => '/admin/positions',
            'position' => $id > 0 ? ($this->service()->position($id) ?? throw new HttpException(404)) : null,
        ]);
    }

    public function savePosition(Request $request): Response
    {
        $id = $this->id($request) ?: null;
        try {
            $this->service()->savePosition($id, $request->post, $this->user());
        } catch (ValidationException $e) {
            return $this->back($id ? "/admin/positions/$id/edit" : '/admin/positions/create', $e->errors, $request->post);
        }
        return $this->redirect('/admin/positions', 'Fonction enregistrée.');
    }

    private function service(): OrganizationService
    {
        return new OrganizationService($this->app->db(), $this->app->audit(), $this->app->channels());
    }
}
