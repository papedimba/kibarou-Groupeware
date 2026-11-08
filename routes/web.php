<?php

declare(strict_types=1);

use App\Core\Router;
use App\Modules\Admin\AdminController;
use App\Modules\Auth\AuthController;
use App\Modules\Dashboard\DashboardController;
use App\Modules\Directory\DirectoryController;
use App\Modules\Directory\PresenceController;
use App\Modules\Install\InstallController;
use App\Modules\Organization\OrganizationController;
use App\Modules\Roles\RolesController;
use App\Modules\Users\ProfileController;
use App\Modules\Users\UsersController;

return static function (Router $r): void {
    // Installation initiale (FTP sans SSH) : 404 sans INSTALL_TOKEN valide ou si déjà installé.
    $r->get('/install', [InstallController::class, 'show'], ['install']);
    $r->post('/install', [InstallController::class, 'store'], ['install']);

    $r->group(['guest'], static function (Router $r) {
        $r->get('/login', [AuthController::class, 'showLogin']);
        $r->post('/login', [AuthController::class, 'login']);
        $r->get('/password/forgot', [AuthController::class, 'showForgot']);
        $r->post('/password/forgot', [AuthController::class, 'forgot']);
        $r->get('/password/reset/{token:token}', [AuthController::class, 'showReset']);
        $r->post('/password/reset/{token:token}', [AuthController::class, 'reset']);
    });

    $r->group(['auth'], static function (Router $r) {
        $r->post('/logout', [AuthController::class, 'logout']);
        $r->get('/', [DashboardController::class, 'index']);

        $r->get('/profile', [ProfileController::class, 'show']);
        $r->post('/profile', [ProfileController::class, 'update']);
        $r->get('/profile/password', [ProfileController::class, 'passwordForm']);
        $r->post('/profile/password', [ProfileController::class, 'changePassword']);
        $r->post('/profile/sessions/revoke', [ProfileController::class, 'revokeSession']);
        // Photo : sa propre photo toujours, celles des autres avec directory.view (contrôlé dans le contrôleur).
        $r->get('/directory/{id}/photo', [DirectoryController::class, 'photo']);
        $r->post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
        $r->post('/profile/photo/delete', [ProfileController::class, 'deletePhoto']);

        $r->group(['perm:directory.view'], static function (Router $r) {
            $r->get('/directory', [DirectoryController::class, 'index']);
            $r->get('/directory/org', [DirectoryController::class, 'org']);
            $r->get('/directory/{id}', [DirectoryController::class, 'show']);
            $r->post('/presence', [PresenceController::class, 'update']);
            $r->post('/presence/clear', [PresenceController::class, 'clear']);
            $r->post('/presence/team', [PresenceController::class, 'team']);
        });

        $r->group(['perm:admin.access'], static function (Router $r) {
            $r->get('/admin', [AdminController::class, 'dashboard']);
            $r->get('/admin/audit', [AdminController::class, 'audit'], ['perm:audit.view']);

            $r->get('/admin/users', [UsersController::class, 'index'], ['perm:users.view']);
            $r->get('/admin/users/{id}', [UsersController::class, 'show'], ['perm:users.view']);
            $r->group(['perm:users.manage'], static function (Router $r) {
                $r->get('/admin/users/create', [UsersController::class, 'create']);
                $r->post('/admin/users', [UsersController::class, 'store']);
                $r->get('/admin/users/{id}/edit', [UsersController::class, 'edit']);
                $r->post('/admin/users/{id}', [UsersController::class, 'update']);
                $r->post('/admin/users/{id}/status', [UsersController::class, 'toggleStatus']);
                $r->post('/admin/users/{id}/reset-link', [UsersController::class, 'sendReset']);
                $r->post('/admin/users/{id}/photo', [UsersController::class, 'uploadPhoto']);
                $r->post('/admin/users/{id}/photo/delete', [UsersController::class, 'deletePhoto']);
                $r->get('/admin/users/{id}/handover', [UsersController::class, 'handover']);
                $r->post('/admin/users/{id}/handover/done', [UsersController::class, 'handoverDone']);
            });
            $r->post('/admin/users/{id}/sessions/revoke', [UsersController::class, 'revokeSessions'], ['perm:sessions.manage']);

            $r->group(['perm:organization.manage'], static function (Router $r) {
                $r->get('/admin/departments', [OrganizationController::class, 'departments']);
                $r->get('/admin/departments/create', [OrganizationController::class, 'departmentForm']);
                $r->post('/admin/departments', [OrganizationController::class, 'saveDepartment']);
                $r->get('/admin/departments/{id}/edit', [OrganizationController::class, 'departmentForm']);
                $r->post('/admin/departments/{id}', [OrganizationController::class, 'saveDepartment']);
                $r->get('/admin/positions', [OrganizationController::class, 'positions']);
                $r->get('/admin/positions/create', [OrganizationController::class, 'positionForm']);
                $r->post('/admin/positions', [OrganizationController::class, 'savePosition']);
                $r->get('/admin/positions/{id}/edit', [OrganizationController::class, 'positionForm']);
                $r->post('/admin/positions/{id}', [OrganizationController::class, 'savePosition']);
            });

            $r->group(['perm:roles.manage'], static function (Router $r) {
                $r->get('/admin/roles', [RolesController::class, 'index']);
                $r->get('/admin/roles/create', [RolesController::class, 'form']);
                $r->post('/admin/roles', [RolesController::class, 'save']);
                $r->get('/admin/roles/{id}/edit', [RolesController::class, 'form']);
                $r->post('/admin/roles/{id}', [RolesController::class, 'save']);
                $r->post('/admin/roles/{id}/delete', [RolesController::class, 'delete']);
            });
        });
    });
};
