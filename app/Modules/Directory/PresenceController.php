<?php

declare(strict_types=1);

namespace App\Modules\Directory;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

final class PresenceController extends Controller
{
    public function update(Request $request): Response
    {
        try {
            $this->app->presence()->set($this->user(), $this->target($request), $request->post);
        } catch (ValidationException $e) {
            return $this->back($this->returnTo($request), $e->errors, $request->post);
        }
        return $this->redirect($this->returnTo($request), 'Statut enregistré.');
    }

    public function team(Request $request): Response
    {
        try {
            $n = $this->app->presence()->bulkSet($this->user(), $request->intList('user_ids'), $request->post);
        } catch (ValidationException $e) {
            return $this->back('/', $e->errors, $request->post);
        }
        return $this->redirect('/', "Statut enregistré pour $n personne(s).");
    }

    public function clear(Request $request): Response
    {
        $this->app->presence()->clear($this->user(), $this->target($request));
        return $this->redirect($this->returnTo($request), 'Statut effacé.');
    }

    private function target(Request $request): int
    {
        return $request->int('user_id') ?: (int) $this->user()['id'];
    }

    /** Seuls les chemins internes sont acceptés (pas de redirection ouverte). */
    private function returnTo(Request $request): string
    {
        $to = $request->str('return_to', '/');
        return preg_match('#^/[a-z0-9/_-]*$#i', $to) ? $to : '/';
    }
}
