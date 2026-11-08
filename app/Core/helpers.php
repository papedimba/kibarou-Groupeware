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

/** Résumé lisible d'un user-agent : « iPhone · Safari ». */
function device_label(string $ua): string
{
    $os = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone',
        str_contains($ua, 'iPad') => 'iPad',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS X') => 'Mac',
        str_contains($ua, 'Linux') => 'Linux',
        default => null,
    };
    // Edge et Chrome contiennent aussi « Safari » : l'ordre compte.
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => null,
    };
    $parts = array_filter([$os, $browser]);
    return $parts === [] ? 'Appareil inconnu' : implode(' · ', $parts);
}

/**
 * Statut affiché d'un compte : libellé et couleur Bootstrap.
 * @param array<string,mixed> $user
 * @return array{0:string,1:string}
 */
function user_status(array $user): array
{
    return match (true) {
        ($user['status'] ?? '') === 'disabled' => ['Désactivé', 'secondary'],
        empty($user['last_login_at']) => ['Pas encore connecté', 'warning'],
        default => ['Actif', 'success'],
    };
}

/**
 * Photo de profil (servie par /directory/{id}/photo) ou, à défaut, initiales. Décorative : le nom est affiché à côté.
 * @param array<string,mixed> $user
 */
function avatar(array $user, string $class = ''): string
{
    $classes = trim('kb-avatar ' . $class);
    if (!empty($user['avatar_path'])) {
        $version = basename((string) $user['avatar_path'], '.webp');
        return '<img class="' . e($classes) . '" src="' . e(url('/directory/' . (int) $user['id'] . '/photo?v=' . $version)) . '" alt="" loading="lazy" width="64" height="64">';
    }
    return '<span class="' . e($classes) . '" aria-hidden="true">' . e(initials((string) $user['first_name'], (string) $user['last_name'])) . '</span>';
}

/** Date relative simple pour les listes : « à l'instant », « il y a 5 min », « il y a 3 h », « hier », puis la date. */
function relative_time(string $utc): string
{
    $tz = new DateTimeZone((string) App::instance()->config('app.timezone', 'UTC'));
    $then = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz);
    $now = new DateTimeImmutable('now', $tz);
    $seconds = $now->getTimestamp() - $then->getTimestamp();
    return match (true) {
        $seconds < 60 => 'à l\'instant',
        $seconds < 3600 => 'il y a ' . intdiv($seconds, 60) . ' min',
        $then->format('Y-m-d') === $now->format('Y-m-d') => 'il y a ' . intdiv($seconds, 3600) . ' h',
        $then->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d') => 'hier',
        default => $then->format('d/m/Y'),
    };
}

/**
 * Texte de message : échappé, retours à la ligne conservés, seuls les liens http(s) rendus cliquables.
 * Le texte est échappé AVANT la détection des liens : rien de ce qu'écrit l'utilisateur n'est interprété.
 */
function linkify(string $text): string
{
    $html = preg_replace_callback('~\bhttps?://[^\s<]+~i', static function (array $m): string {
        $url = rtrim($m[0], '.,;:!?)');
        $rest = substr($m[0], strlen($url));
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $rest;
    }, e($text)) ?? e($text);
    return nl2br($html, false);
}

/** Séparateur de jour des conversations : « Aujourd'hui », « Hier » ou la date. */
function day_label(string $utc): string
{
    $tz = new DateTimeZone((string) App::instance()->config('app.timezone', 'UTC'));
    $day = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d');
    $today = new DateTimeImmutable('today', $tz);
    return match ($day) {
        $today->format('Y-m-d') => 'Aujourd\'hui',
        $today->modify('-1 day')->format('Y-m-d') => 'Hier',
        default => format_date($utc, 'd/m/Y'),
    };
}

/** Taille lisible : « 840 octets », « 240 Ko », « 1,5 Mo », « 2,1 Go ». */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' octets';
    }
    if ($bytes < 1024 * 1024) {
        return (int) round($bytes / 1024) . ' Ko';
    }
    [$value, $unit] = $bytes < 1024 ** 3 ? [$bytes / 1024 ** 2, 'Mo'] : [$bytes / 1024 ** 3, 'Go'];
    return str_replace('.', ',', rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.')) . ' ' . $unit;
}

/** Date courante UTC au format SQL. */
function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}
