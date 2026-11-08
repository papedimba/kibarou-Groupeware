<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Application de test : base dédiée, recréée à chaque lancement de la suite. */
/** @param array<string,mixed> $app surcharges de la section app (ex. base_path) */
function test_app(array $app = []): App
{
    return App::boot(dirname(__DIR__), [
        'app' => ['env' => 'testing', 'debug' => false, 'url' => 'https://gw.test', 'install_token' => str_repeat('a', 40), 'base_path' => '', ...$app],
        'db' => [
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => 3306,
            'name' => getenv('TEST_DB_DATABASE') ?: 'kibarou_test',
            'user' => getenv('TEST_DB_USERNAME') ?: 'kibarou',
            'pass' => getenv('TEST_DB_PASSWORD') ?: 'kibarou',
        ],
        'session' => ['secure' => true],
        'paths' => ['storage' => sys_get_temp_dir() . '/kibarou-test-storage'],
    ]);
}

$app = test_app();
$pdo = $app->db()->pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec("DROP TABLE `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
(new Migrator($app->db(), $app->config('paths.migrations')))->migrate();
// Les identifiants repartent de 1 à chaque lancement : on vide les photos de test du lancement précédent.
foreach (glob($app->config('paths.storage') . '/private/avatars/*.webp') ?: [] as $file) {
    unlink($file);
}
// Pièces jointes de test du lancement précédent.
if (is_dir($app->config('paths.storage') . '/private/chat')) {
    exec('rm -rf ' . escapeshellarg($app->config('paths.storage') . '/private/chat'));
}
