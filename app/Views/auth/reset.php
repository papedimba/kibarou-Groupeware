<?php
/** @var string $token @var array<string,string> $errors */
$this->layout('layouts/auth', ['title' => 'Nouveau mot de passe']);
?>
<h1 class="h4 fw-bold mb-1">Nouveau mot de passe</h1>
<p class="text-secondary small mb-4">Au moins 10 caractères, avec au moins une lettre et un chiffre.</p>
<form method="post" action="<?= e(url('/password/reset/' . $token)) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="password">Nouveau mot de passe</label>
        <input class="form-control<?= invalid($errors, 'password') ?>" type="password" id="password" name="password" autocomplete="new-password" required autofocus>
        <?= field_error($errors, 'password') ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password_confirmation">Confirmation</label>
        <input class="form-control<?= invalid($errors, 'password_confirmation') ?>" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
        <?= field_error($errors, 'password_confirmation') ?>
    </div>
    <button class="btn btn-primary w-100" type="submit">Enregistrer</button>
</form>
