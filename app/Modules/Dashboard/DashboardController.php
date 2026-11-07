<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $db = $this->app->db();
        return $this->view('dashboard/index', [
            'currentPath' => '/',
            'department' => $user['department_id']
                ? $db->value('SELECT name FROM departments WHERE id = ?', [$user['department_id']])
                : null,
            'modules' => $db->fetchAll('SELECT slug, name, is_enabled FROM modules ORDER BY sort_order'),
        ]);
    }
}
