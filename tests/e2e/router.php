<?php
// Routeur pour le serveur PHP intégré (développement uniquement) : sert les fichiers de public/ tels quels.
$file = __DIR__ . '/../../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_URI'] !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/../../public/index.php';
