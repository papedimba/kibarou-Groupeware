<?php
/** @var array<string,mixed> $currentUser @var array<string,string> $errors */
$this->layout('layouts/app', ['title' => 'Mot de passe', 'breadcrumbs' => [['Mon profil', '/profile'], ['Mot de passe', null]]]);
?>
<div class="kb-page-title"><h1>Changer mon mot de passe</h1></div>
<?php if ((int) $currentUser['must_change_password'] === 1): ?>
    <div class="alert alert-warning">Pour des raisons de sécurité, vous devez choisir un nouveau mot de passe avant de continuer.</div>
<?php endif; ?>
<div class="card col-lg-6">
    <div class="card-body">
        <form method="post" action="<?= e(url('/profile/password')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="current_password">Mot de passe actuel</label>
                <input class="form-control<?= invalid($errors, 'current_password') ?>" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                <?= field_error($errors, 'current_password') ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Nouveau mot de passe</label>
                <input class="form-control<?= invalid($errors, 'password') ?>" type="password" id="password" name="password" autocomplete="new-password" required aria-describedby="pw-help">
                <div id="pw-help" class="form-text">Au moins 10 caractères, avec au moins une lettre et un chiffre.</div>
                <?= field_error($errors, 'password') ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password_confirmation">Confirmation</label>
                <input class="form-control<?= invalid($errors, 'password_confirmation') ?>" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                <?= field_error($errors, 'password_confirmation') ?>
            </div>
            <button class="btn btn-primary" type="submit">Enregistrer</button>
        </form>
    </div>
</div>
