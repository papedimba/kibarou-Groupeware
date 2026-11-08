<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;

final class NotificationsController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $userId = (int) $this->user()['id'];
        $page = max(1, $request->int('page', 1));
        $db = $this->app->db();
        $items = $db->fetchAll(
            'SELECT id, title, body, url, created_at, read_at FROM notifications WHERE user_id = ?
             ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . Paginator::offset($page, self::PER_PAGE),
            [$userId],
        );
        $total = (int) $db->value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId]);
        return $this->view('notifications/index', [
            'currentPath' => '/notifications',
            'items' => new Paginator($items, $total, $page, self::PER_PAGE),
        ]);
    }

    /** Interrogation légère : non-lues plus récentes que ?since=, au plus 10. */
    public function poll(Request $request): Response
    {
        $userId = (int) $this->user()['id'];
        $prefs = $this->app->notifier()->preferences($userId);
        $rows = $this->app->db()->fetchAll(
            'SELECT id, type, title, url FROM notifications WHERE user_id = ? AND read_at IS NULL AND id > ? ORDER BY id LIMIT 10',
            [$userId, max(0, $request->int('since'))],
        );
        return Response::json([
            'unread' => $this->app->notifier()->unreadCount($userId),
            'items' => array_map(static fn ($r) => [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'url' => $r['url'] !== null ? url((string) $r['url']) : null,
                'alert' => (bool) ($prefs[$r['type']]['browser'] ?? false),
            ], $rows),
        ]);
    }

    public function preferences(Request $request): Response
    {
        return $this->view('notifications/preferences', [
            'currentPath' => '/profile',
            'prefs' => $this->app->notifier()->preferences((int) $this->user()['id']),
        ]);
    }

    public function savePreferences(Request $request): Response
    {
        $this->app->notifier()->savePreferences((int) $this->user()['id'], $request->post);
        return $this->redirect('/profile/notifications', 'Préférences enregistrées.');
    }

    /** Marque lue puis ouvre la page concernée. Seul le destinataire y a accès. */
    public function open(Request $request): Response
    {
        $row = $this->app->db()->fetch(
            'SELECT id, url FROM notifications WHERE id = ? AND user_id = ?',
            [$this->id($request), $this->user()['id']],
        ) ?? throw new HttpException(404);
        $this->app->db()->run('UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP()) WHERE id = ?', [$row['id']]);
        return $this->redirect($row['url'] !== null ? (string) $row['url'] : '/notifications');
    }

    public function readAll(Request $request): Response
    {
        $this->app->db()->run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [$this->user()['id']]);
        return $this->redirect('/notifications', 'Toutes vos notifications sont marquées comme lues.');
    }
}
