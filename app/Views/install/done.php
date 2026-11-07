<?php $this->layout('layouts/auth', ['title' => 'Installation terminée']); ?>
<h1 class="h4 fw-bold mb-3">Installation terminée</h1>
<p>Le compte super administrateur a été créé.</p>
<div class="alert alert-warning small">Supprimez maintenant la ligne <code>INSTALL_TOKEN</code> du fichier <code>.env</code> sur le serveur.</div>
<a class="btn btn-primary w-100" href="<?= e(url('/login')) ?>">Se connecter</a>
