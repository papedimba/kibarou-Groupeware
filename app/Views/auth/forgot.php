<?php
/** @var array<string,string> $errors */
$this->layout('layouts/auth', ['title' => 'Mot de passe oublié']);
?>
<h1 class="h4 fw-bold mb-1">Mot de passe oublié</h1>
<p class="text-secondary small mb-4">Saisissez votre adresse email : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
<form method="post" action="<?= e(url('/password/forgot')) ?>">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Adresse email</label>
        <input class="form-control" type="email" id="email" name="email" autocomplete="email" required autofocus>
    </div>
    <button class="btn btn-primary w-100" type="submit">Envoyer le lien</button>
</form>
<p class="text-center mt-3 mb-0 small"><a href="<?= e(url('/login')) ?>">Retour à la connexion</a></p>
