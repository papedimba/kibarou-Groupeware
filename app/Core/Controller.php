<?php

declare(strict_types=1);

namespace App\Core;

/** Base des contrôleurs : uniquement des raccourcis de présentation, pas de logique métier. */
abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        $session = $this->app->session();
        $view = $this->app->view();
        $current = $this->app->auth()->user();
        $view->share('currentUser', $current);
        $view->share('unreadCount', $current !== null ? $this->app->notifier()->unreadCount((int) $current['id']) : 0);
        $view->share('lastNotificationId', $current !== null
            ? (int) $this->app->db()->value('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?', [$current['id']])
            : 0);
        $view->share('flash', $session->getFlash('message'));
        $view->share('flashType', $session->getFlash('type', 'success'));
        $view->share('errors', $session->getFlash('errors', []));
        $view->share('old', $session->getFlash('old', []));
        $view->share('currentPath', $data['currentPath'] ?? '');
        return new Response($view->render($template, $data), $status);
    }

    protected function redirect(string $path, ?string $message = null, string $type = 'success'): Response
    {
        if ($message !== null) {
            $this->app->session()->flash('message', $message);
            $this->app->session()->flash('type', $type);
        }
        return Response::redirect(url($path));
    }

    /**
     * Redirige vers le formulaire avec les erreurs et les saisies (sans les mots de passe).
     * @param array<string,string> $errors
     * @param array<string,mixed> $old
     */
    protected function back(string $path, array $errors, array $old = []): Response
    {
        $old = array_filter($old, static fn ($k) => !str_contains((string) $k, 'password') && $k !== '_csrf', ARRAY_FILTER_USE_KEY);
        $this->app->session()->flash('errors', $errors);
        $this->app->session()->flash('old', $old);
        return Response::redirect(url($path));
    }

    /** @return array<string,mixed> */
    protected function user(): array
    {
        return $this->app->auth()->user() ?? throw new HttpException(401);
    }

    protected function id(Request $request, string $key = 'id'): int
    {
        return (int) ($request->params[$key] ?? 0);
    }
}
