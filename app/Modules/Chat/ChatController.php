<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Modules\Users\UserRepository;

final class ChatController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->page(null);
    }

    public function show(Request $request): Response
    {
        return $this->page($this->id($request));
    }

    public function newForm(Request $request): Response
    {
        return $this->view('chat/new', [
            'currentPath' => '/chat',
            'people' => array_values(array_filter((new UserRepository($this->app->db()))->options(), fn ($p) => (int) $p['id'] !== (int) $this->user()['id'])),
        ]);
    }

    public function create(Request $request): Response
    {
        $members = $request->intList('members');
        try {
            $cid = count($members) === 1 && $request->str('name') === ''
                ? $this->app->chat()->directWith($this->user(), $members[0])
                : $this->app->chat()->createGroup($this->user(), $request->str('name'), $members);
        } catch (ValidationException $e) {
            return $this->back('/chat/new', $e->errors, $request->post);
        }
        return $this->redirect('/chat/' . $cid);
    }

    public function with(Request $request): Response
    {
        return $this->redirect('/chat/' . $this->app->chat()->directWith($this->user(), $this->id($request, 'userId')));
    }

    /** Envoi sans JavaScript (formulaire classique). */
    public function send(Request $request): Response
    {
        $cid = $this->id($request);
        try {
            $this->app->chat()->send($this->user(), $cid, (string) $request->input('body', ''));
        } catch (ValidationException $e) {
            return $this->back('/chat/' . $cid, $e->errors, $request->post);
        }
        return $this->redirect('/chat/' . $cid);
    }

    public function addMembers(Request $request): Response
    {
        if ($request->intList('members') === []) {
            return $this->redirect('/chat/' . $this->id($request), 'Cochez au moins une personne à ajouter.', 'danger');
        }
        return $this->groupAction($request, fn ($cid) => $this->app->chat()->addMembers($this->user(), $cid, $request->intList('members')), 'Membres ajoutés.');
    }

    public function rename(Request $request): Response
    {
        return $this->groupAction($request, fn ($cid) => $this->app->chat()->rename($this->user(), $cid, $request->str('name')), 'Groupe renommé.');
    }

    public function leave(Request $request): Response
    {
        $this->app->chat()->leave($this->user(), $this->id($request));
        return $this->redirect('/chat', 'Vous avez quitté le groupe.');
    }

    /** API : évènements postérieurs à ?since= dans mes conversations, et non-lus. */
    public function updates(Request $request): Response
    {
        $me = (int) $this->user()['id'];
        $repo = new ChatRepository($this->app->db());
        $unread = $repo->unreadByConversation($me);
        return Response::json([
            'events' => array_map(self::event(...), $repo->events($me, max(0, $request->int('since')))),
            'unread' => (object) array_map('intval', $unread),
            'total_unread' => array_sum($unread),
        ]);
    }

    public function history(Request $request): Response
    {
        $me = (int) $this->user()['id'];
        $cid = $this->id($request);
        $this->app->chat()->requireMember($me, $cid);
        $before = $request->int('before');
        return Response::json(['events' => array_map(self::event(...), (new ChatRepository($this->app->db()))->history($me, $cid, $before > 0 ? $before : null))]);
    }

    public function apiSend(Request $request): Response
    {
        $cid = $this->id($request);
        $sent = $this->app->chat()->send($this->user(), $cid, (string) $request->input('body', ''));
        $row = (new ChatRepository($this->app->db()))->history((int) $this->user()['id'], $cid, $sent['event_id'] + 1, 1);
        return Response::json(['event' => self::event($row[0])]);
    }

    /** Accusé de lecture ; passif (ne prolonge pas la session) sauf si active=1 — voir App::handle. */
    public function read(Request $request): Response
    {
        $this->app->chat()->markRead($this->user(), $this->id($request), $request->int('event_id'));
        return Response::json(['ok' => true]);
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    private static function event(array $e): array
    {
        return [
            'id' => (int) $e['id'],
            'conversation_id' => (int) $e['conversation_id'],
            'type' => (string) $e['type'],
            'sender_id' => $e['sender_id'] !== null ? (int) $e['sender_id'] : null,
            'sender_name' => $e['type'] === 'message' ? $e['actor_name'] : null,
            'actor_id' => $e['actor_id'] !== null ? (int) $e['actor_id'] : null,
            'actor_name' => $e['actor_name'],
            'target_user_id' => $e['target_user_id'] !== null ? (int) $e['target_user_id'] : null,
            'target_name' => $e['target_name'],
            'body' => $e['body'],
            'created_at' => str_replace(' ', 'T', (string) $e['created_at']) . 'Z',
        ];
    }

    private function groupAction(Request $request, callable $action, string $done): Response
    {
        $cid = $this->id($request);
        try {
            $action($cid);
        } catch (ValidationException $e) {
            return $this->redirect('/chat/' . $cid, implode(' ', $e->errors), 'danger');
        }
        return $this->redirect('/chat/' . $cid, $done);
    }

    private function page(?int $cid): Response
    {
        $me = (int) $this->user()['id'];
        $repo = new ChatRepository($this->app->db());
        $data = ['currentPath' => '/chat', 'conversations' => $repo->conversationsFor($me), 'conversation' => null,
            'poll' => (array) $this->app->config('chat.poll'), 'since' => (int) $this->app->db()->value('SELECT COALESCE(MAX(id), 0) FROM chat_events')];
        if ($cid !== null) {
            $membership = $this->app->chat()->requireMember($me, $cid);
            $events = $repo->history($me, $cid, null);
            if ($events !== []) {
                $this->app->chat()->markRead($this->user(), $cid, (int) end($events)['id']);
            }
            $current = null;
            foreach ($data['conversations'] as &$c) {
                if ((int) $c['id'] === $cid) {
                    $c['unread'] = 0;
                    $current = $c;
                }
            }
            unset($c);
            $data['conversation'] = $current ?? throw new HttpException(404);
            $data['membership'] = $membership;
            $data['events'] = $events;
            $data['members'] = $repo->members($cid);
            $data['lastEventId'] = $events !== [] ? (int) end($events)['id'] : (int) $membership['joined_event_id'];
            $data['people'] = $membership['type'] === 'group' ? (new UserRepository($this->app->db()))->options() : [];
        }
        return $this->view('chat/index', $data);
    }
}
