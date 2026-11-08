<?php
/** Page d'erreur autonome (ne dépend ni de la session ni de la base). @var int $status @var string $message */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Erreur <?= (int) $status ?> · Kibarou Groupware</title>
    <link rel="icon" href="<?= e(url('favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<main class="kb-auth">
    <div class="kb-auth-card text-center">
        <p class="display-5 fw-bold mb-2"><?= (int) $status ?></p>
        <p class="mb-4"><?= e($message) ?></p>
        <a class="btn btn-primary" href="<?= e(url('/')) ?>">Retour à l'accueil</a>
    </div>
</main>
</body>
</html>
