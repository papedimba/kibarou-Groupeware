<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Modules\Organization\OrganizationService;
use App\Modules\Roles\RoleService;

final class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr($request->str('q'), 0, 100),
            'status' => $request->str('status'),
            'department_id' => $request->int('department_id'),
        ];
        return $this->view('admin/users/index', [
            'currentPath' => '/admin/users',
            'users' => $this->repo()->paginate($filters, $request->int('page', 1)),
            'filters' => $filters,
            'departments' => $this->org()->departmentOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->service()->create($request->post, $request->intList('roles'), (string) $request->input('temp_password', ''), $this->user());
        } catch (ValidationException $e) {
            return $this->back('/admin/users/create', $e->errors, $request->post);
        }
        $msg = (string) $request->input('temp_password', '') !== ''
            ? 'Compte créé. L\'utilisateur devra changer son mot de passe temporaire à la première connexion.'
            : 'Compte créé. Un lien d\'activation a été mis en file d\'envoi par email.';
        return $this->redirect('/admin/users/' . $id, $msg);
    }

    public function show(Request $request): Response
    {
        $id = $this->id($request);
        $target = $this->repo()->find($id) ?? throw new HttpException(404);
        return $this->view('admin/users/show', [
            'currentPath' => '/admin/users',
            'target' => $target,
            'roles' => $this->repo()->roleSlugs($id),
            'sessions' => can('sessions.manage') ? $this->repo()->sessions($id) : [],
            'department' => $target['department_id'] ? $this->org()->department((int) $target['department_id']) : null,
            'position' => $target['position_id'] ? $this->org()->position((int) $target['position_id']) : null,
            'manager' => $target['manager_id'] ? $this->repo()->find((int) $target['manager_id']) : null,
            'audit' => can('audit.view') ? $this->app->db()->fetchAll(
                'SELECT * FROM audit_logs WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 20',
                ['user', $id],
            ) : [],
        ]);
    }

    public function edit(Request $request): Response
    {
        $target = $this->repo()->find($this->id($request)) ?? throw new HttpException(404);
        return $this->form($target);
    }

    public function update(Request $request): Response
    {
        $id = $this->id($request);
        try {
            $this->service()->update($id, $request->post, $request->intList('roles'), $this->user());
        } catch (ValidationException $e) {
            return $this->back("/admin/users/$id/edit", $e->errors, $request->post);
        }
        return $this->redirect("/admin/users/$id", 'Utilisateur mis à jour.');
    }

    public function toggleStatus(Request $request): Response
    {
        $id = $this->id($request);
        $activate = $request->str('status') === 'active';
        try {
            $this->service()->setStatus($id, $activate, $this->user());
        } catch (ValidationException $e) {
            return $this->redirect("/admin/users/$id", implode(' ', $e->errors), 'danger');
        }
        return $this->redirect("/admin/users/$id", $activate ? 'Compte activé.' : 'Compte désactivé et sessions fermées.');
    }

    public function sendReset(Request $request): Response
    {
        $id = $this->id($request);
        $this->service()->sendResetLink($id, $this->user());
        return $this->redirect("/admin/users/$id", 'Lien de réinitialisation mis en file d\'envoi.');
    }

    public function revokeSessions(Request $request): Response
    {
        $id = $this->id($request);
        $count = $this->service()->revokeSessions($id, $this->user());
        return $this->redirect("/admin/users/$id", "$count session(s) fermée(s).");
    }

    /** @param array<string,mixed>|null $target */
    private function form(?array $target): Response
    {
        return $this->view('admin/users/form', [
            'currentPath' => '/admin/users',
            'target' => $target,
            'userRoleIds' => $target !== null ? $this->repo()->roleIds((int) $target['id']) : [],
            'roles' => (new RoleService($this->app->db(), $this->app->audit()))->all(),
            'departments' => $this->org()->departmentOptions(),
            'positions' => $this->org()->positionOptions(),
            'managers' => $this->repo()->options(),
            'actorIsSuper' => $this->app->rbac()->hasRole($this->user(), 'super_admin'),
        ]);
    }

    private function repo(): UserRepository
    {
        return new UserRepository($this->app->db());
    }

    private function org(): OrganizationService
    {
        return new OrganizationService($this->app->db(), $this->app->audit());
    }

    private function service(): UserService
    {
        return new UserService(
            $this->app->db(),
            $this->repo(),
            $this->app->rbac(),
            $this->app->audit(),
            $this->app->passwordResets(),
            (int) $this->app->config('auth.password_min_length'),
            (string) $this->app->config('app.url'),
        );
    }
}
