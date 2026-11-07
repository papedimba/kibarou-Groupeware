<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Controller;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;

final class AdminController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $db = $this->app->db();
        $stats = $db->fetch(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'active') AS active_users,
                (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status = 'disabled') AS disabled_users,
                (SELECT COUNT(*) FROM departments WHERE is_active = 1) AS departments,
                (SELECT COUNT(DISTINCT user_id) FROM sessions WHERE user_id IS NOT NULL AND last_activity_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE) AS online_users,
                (SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR) AS failed_logins_24h,
                (SELECT COUNT(*) FROM mail_queue WHERE status = 'failed') AS failed_mails,
                (SELECT COUNT(*) FROM mail_queue WHERE status = 'pending') AS pending_mails",
        );
        $cronLast = $db->value("SELECT `value` FROM settings WHERE `key` = 'cron_last_run'");
        return $this->view('admin/dashboard', [
            'currentPath' => '/admin',
            'stats' => $stats,
            'cronLast' => $cronLast,
            'recent' => can('audit.view') ? $this->auditRows(1, 10, [])->items : [],
        ]);
    }

    public function audit(Request $request): Response
    {
        $filters = ['action' => mb_substr($request->str('action'), 0, 100), 'user_id' => $request->int('user_id')];
        return $this->view('admin/audit', [
            'currentPath' => '/admin/audit',
            'logs' => $this->auditRows($request->int('page', 1), 50, $filters),
            'filters' => $filters,
        ]);
    }

    /** @param array{action?:string,user_id?:int} $filters */
    private function auditRows(int $page, int $perPage, array $filters): Paginator
    {
        $where = ['1 = 1'];
        $params = [];
        if (($filters['action'] ?? '') !== '') {
            $where[] = 'a.action = ?';
            $params[] = $filters['action'];
        }
        if (($filters['user_id'] ?? 0) > 0) {
            $where[] = 'a.user_id = ?';
            $params[] = $filters['user_id'];
        }
        $sql = implode(' AND ', $where);
        $db = $this->app->db();
        $total = (int) $db->value("SELECT COUNT(*) FROM audit_logs a WHERE $sql", $params);
        $items = $db->fetchAll(
            "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name, CONCAT(t.first_name, ' ', t.last_name) AS target_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN users t ON a.entity_type = 'user' AND t.id = a.entity_id
             WHERE $sql ORDER BY a.id DESC LIMIT $perPage OFFSET " . Paginator::offset($page, $perPage),
            $params,
        );
        return new Paginator($items, $total, max(1, $page), $perPage);
    }
}
