<?php

declare(strict_types=1);

namespace App\Core;

final class SecurityHeaders
{
    public static function apply(Response $response, bool $https): void
    {
        $response->headers += [
            // Aucune ressource externe ni script inline : tous les assets sont auto-hébergés.
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
                . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cache-Control' => 'no-store, private',
        ];
        if ($https) {
            $response->headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
    }
}
