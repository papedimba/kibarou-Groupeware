<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Request;
use App\Core\Router;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('expose_php', '0');

$app = App::boot($root);
$router = new Router();
(require $root . '/routes/web.php')($router);

$app->handle(Request::fromGlobals((string) $app->config('app.base_path', '')), $router)->send();
