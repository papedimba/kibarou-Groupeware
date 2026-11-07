<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Users\UserRepository;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();
        $db = $this->app->db();
        $me = $db->fetch(
            "SELECT d.name AS department, p.title AS position, CONCAT(m.first_name, ' ', m.last_name) AS manager
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN positions p ON p.id = u.position_id
             LEFT JOIN users m ON m.id = u.manager_id AND m.deleted_at IS NULL
             WHERE u.id = ?",
            [$user['id']],
        );
        $alerts = can('admin.access') ? $db->fetch(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE status = 'active' AND deleted_at IS NULL AND last_login_at IS NULL) AS never_logged,
                (SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR) AS failed_logins,
                (SELECT COUNT(*) FROM mail_queue WHERE status = 'failed') AS failed_mails",
        ) : null;
        return $this->view('dashboard/index', [
            'currentPath' => '/',
            'me' => $me,
            'alerts' => $alerts,
            'contacts' => (new UserRepository($db))->adminContacts(),
        ]);
    }
}
