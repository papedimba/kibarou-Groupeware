<?php
/** @var array<string,string> $errors @var array<string,mixed> $old */
$this->layout('layouts/auth');
?>
<h1 class="h4 fw-bold mb-1">Connexion</h1>
<p class="text-secondary small mb-4">Accès réservé aux collaborateurs de Kibarou Construction.</p>
<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Adresse email</label>
        <input class="form-control<?= invalid($errors, 'email') ?>" type="email" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="username" required autofocus>
        <?= field_error($errors, 'email') ?>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Mot de passe</label>
        <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-primary w-100" type="submit">Se connecter</button>
</form>
<p class="text-center mt-3 mb-0 small"><a href="<?= e(url('/password/forgot')) ?>">Mot de passe oublié ?</a></p>
