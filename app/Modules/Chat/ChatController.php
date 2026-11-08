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
        $data = ['currentPath' => '/chat', 'conversations' => $repo->conversationsFor($me), 'conversation' => null];
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
            $data['poll'] = (array) $this->app->config('chat.poll');
        }
        return $this->view('chat/index', $data);
    }
}
