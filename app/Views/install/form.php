<?php
/** @var list<string> $applied @var string $token @var array<string,string> $errors @var array<string,mixed> $old */
$this->layout('layouts/auth', ['title' => 'Installation']);
?>
<h1 class="h4 fw-bold mb-1">Installation</h1>
<?php if ($applied !== []): ?>
    <div class="alert alert-success small">Base de données initialisée (<?= count($applied) ?> migration(s)).</div>
<?php endif; ?>
<p class="text-secondary small mb-4">Créez le compte super administrateur. Cette page disparaît ensuite automatiquement ; retirez aussi <code>INSTALL_TOKEN</code> du fichier <code>.env</code>.</p>
<form method="post" action="<?= e(url('/install')) ?>" novalidate>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <?php foreach (['first_name' => ['Prénom', 'text', 'given-name'], 'last_name' => ['Nom', 'text', 'family-name'], 'email' => ['Email', 'email', 'username']] as $f => [$label, $type, $ac]): ?>
        <div class="mb-3">
            <label class="form-label" for="<?= $f ?>"><?= e($label) ?></label>
            <input class="form-control<?= invalid($errors, $f) ?>" type="<?= $type ?>" id="<?= $f ?>" name="<?= $f ?>" value="<?= e($old[$f] ?? '') ?>" autocomplete="<?= $ac ?>" required>
            <?= field_error($errors, $f) ?>
        </div>
    <?php endforeach; ?>
    <div class="mb-3">
        <label class="form-label" for="password">Mot de passe</label>
        <input class="form-control<?= invalid($errors, 'password') ?>" type="password" id="password" name="password" autocomplete="new-password" required>
        <?= field_error($errors, 'password') ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password_confirmation">Confirmation</label>
        <input class="form-control<?= invalid($errors, 'password_confirmation') ?>" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
        <?= field_error($errors, 'password_confirmation') ?>
    </div>
    <button class="btn btn-primary w-100" type="submit">Créer le compte</button>
</form>
