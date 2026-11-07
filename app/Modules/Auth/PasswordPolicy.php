<?php

declare(strict_types=1);

namespace App\Modules\Auth;

final class PasswordPolicy
{
    /** Retourne un message d'erreur, ou null si le mot de passe est acceptable. */
    public static function check(string $password, int $minLength, string $email = ''): ?string
    {
        if (mb_strlen($password) < $minLength) {
            return "Le mot de passe doit contenir au moins $minLength caractères.";
        }
        if (strlen($password) > 72) {
            // bcrypt ignore silencieusement au-delà de 72 octets.
            return 'Le mot de passe ne doit pas dépasser 72 caractères.';
        }
        if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
        }
        $local = strtolower(strstr($email, '@', true) ?: '');
        if ($local !== '' && str_contains(strtolower($password), $local)) {
            return 'Le mot de passe ne doit pas contenir votre identifiant.';
        }
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
