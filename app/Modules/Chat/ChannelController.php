<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

/** Écrans des salons : liste, création, modification, rejoindre, quitter, archiver. */
final class ChannelController extends Controller
{
    public function index(Request $request): Response
    {
        $archived = $request->str('archived') === '1';
        return $this->view('chat/channels', [
            'currentPath' => '/chat',
            'archived' => $archived,
            'channels' => $this->app->channels()->list($this->user(), $archived),
            'canCreate' => $this->app->rbac()->can($this->user(), 'chat.channels.manage'),
        ]);
    }

    public function newForm(Request $request): Response
    {
        if (!$this->app->rbac()->can($this->user(), 'chat.channels.manage')) {
            throw new HttpException(403);
        }
        return $this->view('chat/channel_form', ['currentPath' => '/chat', 'channel' => null]);
    }

    public function create(Request $request): Response
    {
        try {
            $cid = $this->app->channels()->create($this->user(), $request->str('name'), $request->str('description'));
        } catch (ValidationException $e) {
            return $this->back('/chat/channels/new', $e->errors, $request->post);
        }
        return $this->redirect('/chat/' . $cid, 'Salon créé.');
    }

    public function editForm(Request $request): Response
    {
        $channel = $this->app->db()->fetch("SELECT * FROM conversations WHERE id = ? AND type = 'channel'", [$this->id($request)]) ?? throw new HttpException(404);
        if (!$this->app->channels()->canManage($this->user(), $channel)) {
            throw new HttpException(403);
        }
        return $this->view('chat/channel_form', ['currentPath' => '/chat', 'channel' => $channel]);
    }

    public function update(Request $request): Response
    {
        $cid = $this->id($request);
        try {
            $this->app->channels()->update($this->user(), $cid, $request->str('name'), $request->str('description'));
        } catch (ValidationException $e) {
            return $this->back("/chat/channels/$cid/edit", $e->errors, $request->post);
        }
        return $this->redirect('/chat/channels', 'Salon modifié.');
    }

    public function join(Request $request): Response
    {
        $this->app->channels()->join($this->user(), $this->id($request));
        return $this->redirect('/chat/' . $this->id($request));
    }

    public function leave(Request $request): Response
    {
        try {
            $this->app->channels()->leave($this->user(), $this->id($request));
        } catch (ValidationException $e) {
            return $this->redirect('/chat/' . $this->id($request), implode(' ', $e->errors), 'danger');
        }
        return $this->redirect('/chat/channels', 'Vous avez quitté le salon.');
    }

    public function archive(Request $request): Response
    {
        $this->app->channels()->archive($this->user(), $this->id($request));
        return $this->redirect('/chat/channels', 'Salon archivé : il reste lisible, sans nouveaux messages.');
    }

    public function unarchive(Request $request): Response
    {
        try {
            $this->app->channels()->unarchive($this->user(), $this->id($request));
        } catch (ValidationException $e) {
            return $this->redirect('/chat/channels?archived=1', implode(' ', $e->errors), 'danger');
        }
        return $this->redirect('/chat/channels', 'Salon rouvert.');
    }
}
