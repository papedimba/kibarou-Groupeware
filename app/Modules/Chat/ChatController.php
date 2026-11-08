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
            'events' => array_map(self::event(...), $repo->hydrate($me, $repo->events($me, max(0, $request->int('since'))))),
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
        $repo = new ChatRepository($this->app->db());
        return Response::json(['events' => array_map(self::event(...), $repo->hydrate($me, $repo->history($me, $cid, $before > 0 ? $before : null)))]);
    }

    public function apiSend(Request $request): Response
    {
        $cid = $this->id($request);
        $reply = $request->int('reply_to_id');
        $sent = $this->app->chat()->send($this->user(), $cid, (string) $request->input('body', ''), $request->str('client_id') ?: null,
            $request->intList('attachment_ids'), $reply > 0 ? $reply : null);
        $me = (int) $this->user()['id'];
        $repo = new ChatRepository($this->app->db());
        return Response::json(['event' => self::event($repo->hydrate($me, $repo->history($me, $cid, $sent['event_id'] + 1, 1))[0])]);
    }

    /** Une pièce jointe, envoyée avant le message qui la cite. */
    public function upload(Request $request): Response
    {
        $row = $this->app->chat()->uploadAttachment($this->user(), $this->id($request), (array) ($request->files['file'] ?? []));
        return Response::json(['attachment' => self::attachment(['id' => (int) $row['id'], 'kind' => $row['kind'], 'name' => $row['original_name'],
            'size' => (int) $row['size'], 'width' => $row['width'], 'height' => $row['height']])]);
    }

    public function download(Request $request): Response
    {
        return $this->serve($request, false);
    }

    public function thumb(Request $request): Response
    {
        return $this->serve($request, true);
    }

    private function serve(Request $request, bool $thumb): Response
    {
        $row = $this->app->chat()->attachmentFor((int) $this->user()['id'], $this->id($request));
        $path = $this->app->chatAttachments()->absolutePath($thumb ? ($row['thumb_path'] ?? null) : $row['stored_path']);
        if ($path === null) {
            throw new HttpException(404);
        }
        $response = new Response((string) file_get_contents($path));
        $response->headers['X-Content-Type-Options'] = 'nosniff';
        $response->headers['Cache-Control'] = 'private, max-age=86400';
        if ($row['kind'] === 'image') {
            $response->headers['Content-Type'] = 'image/webp';
            return $response;
        }
        // Jamais affiché dans le navigateur : toujours téléchargé, sous son nom d'origine.
        $name = (string) $row['original_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'fichier';
        $response->headers['Content-Type'] = 'application/octet-stream';
        $response->headers['Content-Disposition'] = 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
        return $response;
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
            'detail' => $e['detail'],
            'message_id' => $e['message_id'] !== null ? (int) $e['message_id'] : null,
            'body' => !empty($e['deleted_at']) ? '' : $e['body'],
            'client_id' => $e['client_id'] ?? null,
            'edited_at' => !empty($e['edited_at']) ? str_replace(' ', 'T', (string) $e['edited_at']) . 'Z' : null,
            'deleted' => (bool) ($e['deleted'] ?? false),
            'reply_to' => $e['reply_to'] ?? null,
            'attachments' => array_map(self::attachment(...), $e['attachments'] ?? []),
            'reactions' => $e['reactions'] ?? [],
            'created_at' => str_replace(' ', 'T', (string) $e['created_at']) . 'Z',
        ];
    }

    public function editMessage(Request $request): Response
    {
        return $this->action($this->app->chat()->edit($this->user(), $this->id($request), (string) $request->input('body', '')));
    }

    public function deleteMessage(Request $request): Response
    {
        return $this->action($this->app->chat()->delete($this->user(), $this->id($request)));
    }

    public function reactMessage(Request $request): Response
    {
        return $this->action($this->app->chat()->react($this->user(), $this->id($request), $request->str('emoji')));
    }

    /** Renvoie l'évènement créé, avec l'état à jour du message visé. */
    private function action(int $eventId): Response
    {
        $me = (int) $this->user()['id'];
        $repo = new ChatRepository($this->app->db());
        $events = array_values(array_filter($repo->events($me, $eventId - 1, 1), static fn ($e) => (int) $e['id'] === $eventId));
        return Response::json(['event' => self::event($repo->hydrate($me, $events)[0])]);
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private static function attachment(array $a): array
    {
        return $a + ['url' => url('/chat/attachments/' . $a['id']), 'thumb_url' => $a['kind'] === 'image' ? url('/chat/attachments/' . $a['id'] . '/thumb') : null];
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
        $data = ['currentPath' => '/chat', 'conversations' => $repo->conversationsFor($me, $cid ?? 0), 'conversation' => null,
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
