<?php

declare(strict_types=1);

use App\Core\App;

/** Échappe une valeur pour une sortie HTML (attributs inclus). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Champ caché CSRF à insérer dans chaque formulaire POST. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App::instance()->session()->csrfToken()) . '">';
}

/** URL absolue-chemin préfixée par le sous-répertoire éventuel de l'application. */
function url(string $path = '/'): string
{
    return rtrim((string) App::instance()->config('app.base_path', ''), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/** Vérifie une permission pour l'utilisateur courant (affichage uniquement ; le serveur revérifie). */
function can(string $permission): bool
{
    $user = App::instance()->auth()->user();
    return $user !== null && App::instance()->rbac()->can($user, $permission);
}

function format_date(?string $utc, string $format = 'd/m/Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $tz = new DateTimeZone((string) App::instance()->config('app.timezone', 'UTC'));
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($format);
}

/** Message d'erreur de validation d'un champ (classe Bootstrap invalid-feedback). @param array<string,string> $errors */
function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>' : '';
}

/** Classe CSS d'un champ en erreur. @param array<string,string> $errors */
function invalid(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

/** Initiales pour l'avatar par défaut. */
function initials(string $first, string $last): string
{
    return mb_strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
}

/** Date courante UTC au format SQL. */
function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}
