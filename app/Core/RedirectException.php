<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Interrompt le traitement pour rediriger (utilisé par les middlewares). */
final class RedirectException extends RuntimeException
{
    public function __construct(public readonly string $to)
    {
        parent::__construct('Redirection');
    }
}
