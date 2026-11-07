<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Transforme toute exception en réponse propre, sans divulguer de détail technique en production. */
final class ErrorHandler
{
    public function __construct(private readonly App $app)
    {
    }

    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof RedirectException) {
            return Response::redirect($e->to);
        }
        if ($e instanceof ValidationException) {
            return $request->wantsJson()
                ? Response::json(['error' => $e->getMessage(), 'errors' => $e->errors], 422)
                : $this->page(422, $e->getMessage());
        }
        if ($e instanceof HttpException) {
            if ($e->status === 401) {
                return $request->wantsJson()
                    ? Response::json(['error' => 'Authentification requise.'], 401)
                    : Response::redirect(url('/login'));
            }
            return $request->wantsJson()
                ? Response::json(['error' => $e->getMessage()], $e->status)
                : $this->page($e->status, $e->getMessage());
        }

        $ref = bin2hex(random_bytes(4));
        $this->app->logger()->error($e::class . ': ' . $e->getMessage(), [
            'ref' => $ref,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'path' => $request->path,
            'trace' => $e->getTraceAsString(),
        ]);
        $message = $this->app->config('app.debug')
            ? $e::class . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')'
            : "Une erreur interne est survenue. Référence : $ref";
        return $request->wantsJson()
            ? Response::json(['error' => $message], 500)
            : $this->page(500, $message);
    }

    private function page(int $status, string $message): Response
    {
        try {
            $body = $this->app->view()->render('errors/error', ['status' => $status, 'message' => $message]);
        } catch (Throwable) {
            $body = '<!doctype html><meta charset="utf-8"><title>Erreur</title><p>' . e($message) . '</p>';
        }
        return new Response($body, $status);
    }
}
