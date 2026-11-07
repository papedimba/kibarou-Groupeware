<?php

declare(strict_types=1);

namespace App\Modules\Roles;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

final class RolesController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('admin/roles/index', ['currentPath' => '/admin/roles', 'roles' => $this->service()->all()]);
    }

    public function form(Request $request): Response
    {
        $id = $this->id($request);
        $role = $id > 0 ? ($this->service()->find($id) ?? throw new HttpException(404)) : null;
        return $this->view('admin/roles/form', [
            'currentPath' => '/admin/roles',
            'role' => $role,
            'grouped' => $this->service()->permissionsByModule(),
            'selected' => $role !== null ? $this->service()->permissionIds($id) : [],
        ]);
    }

    public function save(Request $request): Response
    {
        $id = $this->id($request) ?: null;
        try {
            $this->service()->save($id, $request->post, $request->intList('permissions'), $this->user());
        } catch (ValidationException $e) {
            return $this->back($id ? "/admin/roles/$id/edit" : '/admin/roles/create', $e->errors, $request->post);
        }
        return $this->redirect('/admin/roles', 'Rôle enregistré.');
    }

    public function delete(Request $request): Response
    {
        try {
            $this->service()->delete($this->id($request), $this->user());
        } catch (ValidationException $e) {
            return $this->redirect('/admin/roles', implode(' ', $e->errors), 'danger');
        }
        return $this->redirect('/admin/roles', 'Rôle supprimé.');
    }

    private function service(): RoleService
    {
        return new RoleService($this->app->db(), $this->app->audit());
    }
}
