<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'Kibarou Groupware'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => (bool) Env::get('APP_DEBUG', false),
        'url' => Env::get('APP_URL', 'https://gw.kibarouconstruction.com'),
        'base_path' => Env::get('APP_BASE_PATH', ''),
        // Affichage uniquement : tout est stocké en UTC. Fuseau de l'entreprise à confirmer.
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
        'install_token' => Env::get('INSTALL_TOKEN'),
    ],
    'db' => [
        'host' => Env::get('DB_HOST', 'localhost'),
        'port' => (int) Env::get('DB_PORT', 3306),
        'name' => Env::get('DB_DATABASE', 'kibarou'),
        'user' => Env::get('DB_USERNAME', 'kibarou'),
        'pass' => Env::get('DB_PASSWORD', ''),
    ],
    'session' => [
        'cookie' => 'kb_session',
        'secure' => (bool) Env::get('SESSION_SECURE', true),
        'idle_minutes' => (int) Env::get('SESSION_IDLE_MINUTES', 120),
        'absolute_hours' => (int) Env::get('SESSION_ABSOLUTE_HOURS', 12),
    ],
    'auth' => [
        'max_attempts_per_email' => 5,
        'max_attempts_per_ip' => 20,
        'lockout_minutes' => 15,
        'reset_token_minutes' => 60,
        'password_min_length' => 10,
    ],
    'mail' => [
        'host' => Env::get('MAIL_HOST'),
        'port' => (int) Env::get('MAIL_PORT', 587),
        'username' => Env::get('MAIL_USERNAME'),
        'password' => Env::get('MAIL_PASSWORD'),
        'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
        'from_address' => Env::get('MAIL_FROM_ADDRESS', 'noreply@kibarouconstruction.com'),
        'from_name' => Env::get('MAIL_FROM_NAME', 'Kibarou Groupware'),
        'batch_size' => 20,
        'max_attempts' => 5,
    ],
    // Messagerie : rythme d'interrogation (ms), réglable selon les limites de l'hébergement.
    'chat' => [
        'poll' => [
            'active_ms' => (int) Env::get('CHAT_POLL_ACTIVE_MS', 3000),
            'idle_ms' => (int) Env::get('CHAT_POLL_IDLE_MS', 10000),
            'background_ms' => (int) Env::get('CHAT_POLL_BACKGROUND_MS', 30000),
        ],
    ],
    'retention' => [
        'login_attempts_days' => 90,
        'audit_logs_days' => (int) Env::get('AUDIT_RETENTION_DAYS', 730),
        'mail_queue_days' => 30,
    ],
    'paths' => [
        'root' => dirname(__DIR__),
        'storage' => dirname(__DIR__) . '/storage',
        'views' => dirname(__DIR__) . '/app/Views',
        'migrations' => dirname(__DIR__) . '/database/migrations',
    ],
];
