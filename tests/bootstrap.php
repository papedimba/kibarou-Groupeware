<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Application de test : base dédiée, recréée à chaque lancement de la suite. */
function test_app(): App
{
    return App::boot(dirname(__DIR__), [
        'app' => ['env' => 'testing', 'debug' => false, 'url' => 'https://gw.test', 'install_token' => str_repeat('a', 40)],
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
