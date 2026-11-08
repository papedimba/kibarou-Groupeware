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
    private const HANDOVER_TTL = 600;

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
        $temp = (string) $request->input('temp_password', '');
        if ($temp !== '') {
            // Chiffré en session 10 minutes : survit à une actualisation, effacé par « Terminé ».
            $this->app->session()->seal('handover', ['user_id' => $id, 'password' => $temp], self::HANDOVER_TTL);
            return $this->redirect("/admin/users/$id/handover");
        }
        return $this->redirect('/admin/users/' . $id, 'Compte créé. Un lien d\'activation a été mis en file d\'envoi par email.');
    }

    public function handover(Request $request): Response
    {
        $id = $this->id($request);
        $data = $this->app->session()->unseal('handover');
        if ($data === null || (int) $data['user_id'] !== $id) {
            return $this->redirect("/admin/users/$id", 'Le récapitulatif n\'est plus disponible. Si besoin, envoyez un « Lien de réinitialisation ».', 'danger');
        }
        return $this->view('admin/users/handover', [
            'currentPath' => '/admin/users',
            'target' => $this->repo()->find($id) ?? throw new HttpException(404),
            'tempPassword' => (string) $data['password'],
            'loginUrl' => rtrim((string) $this->app->config('app.url'), '/') . '/login',
            'ttlMinutes' => intdiv(self::HANDOVER_TTL, 60),
        ]);
    }

    public function handoverDone(Request $request): Response
    {
        $this->app->session()->forget('handover');
        return $this->redirect('/admin/users/' . $this->id($request), 'Récapitulatif fermé.');
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
                "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name, CONCAT(t.first_name, ' ', t.last_name) AS target_name
                 FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users t ON t.id = a.entity_id
                 WHERE a.entity_type = ? AND a.entity_id = ? ORDER BY a.id DESC LIMIT 20",
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

    public function uploadPhoto(Request $request): Response
    {
        $id = $this->id($request);
        $this->repo()->find($id) ?? throw new HttpException(404);
        try {
            $this->app->avatars()->store($id, (array) ($request->files['photo'] ?? []));
        } catch (ValidationException $e) {
            return $this->redirect("/admin/users/$id", implode(' ', $e->errors), 'danger');
        }
        $this->app->audit()->log((int) $this->user()['id'], 'profile.photo_updated', 'user', $id);
        return $this->redirect("/admin/users/$id", 'Photo enregistrée.');
    }

    public function deletePhoto(Request $request): Response
    {
        $id = $this->id($request);
        $this->repo()->find($id) ?? throw new HttpException(404);
        $this->app->avatars()->remove($id);
        $this->app->audit()->log((int) $this->user()['id'], 'user.photo_removed', 'user', $id);
        return $this->redirect("/admin/users/$id", 'Photo retirée.');
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
            $this->app->notifier(),
        );
    }
}
