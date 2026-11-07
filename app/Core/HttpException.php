<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Erreur HTTP « attendue » : son message est montré à l'utilisateur. */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Requête invalide.',
            403 => 'Accès refusé.',
            404 => 'Page introuvable.',
            405 => 'Méthode non autorisée.',
            419 => 'Votre session a expiré. Rechargez la page et réessayez.',
            429 => 'Trop de tentatives. Réessayez plus tard.',
            default => 'Une erreur est survenue.',
        };
    }
}
