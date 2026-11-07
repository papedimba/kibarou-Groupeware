<?php
/** @var string $content @var string|null $title */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'Connexion') ?> · Kibarou Groupware</title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<main class="kb-auth">
    <div class="kb-auth-card">
        <div class="kb-brand">
            <span class="kb-brand-mark" aria-hidden="true">K</span>
            <span>Kibarou Groupware<small>Kibarou Construction</small></span>
        </div>
        <?php if (!empty($flash)): ?>
            <div class="alert alert-<?= e(($flashType ?? '') === 'danger' ? 'danger' : 'success') ?> small" role="alert"><?= e($flash) ?></div>
        <?php endif; ?>
        <?= $content ?>
    </div>
</main>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
