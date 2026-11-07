<?php
/** Récapitulatif à remettre après création avec mot de passe temporaire (affiché une seule fois). @var array<string,mixed> $target @var string $tempPassword @var string $loginUrl */
$name = $target['first_name'] . ' ' . $target['last_name'];
$this->layout('layouts/app', ['title' => 'Compte créé', 'breadcrumbs' => [['Administration', '/admin'], ['Utilisateurs', '/admin/users'], ['Compte créé', null]]]);
?>
<div class="kb-page-title"><h1>Compte créé pour <?= e($name) ?></h1></div>
<div class="card col-lg-7">
    <div class="card-body">
        <p>Remettez ces informations à <?= e($name) ?> de vive voix ou sur papier, jamais par messagerie. <strong>Elles ne seront plus affichées</strong> après avoir quitté cette page.</p>
        <dl class="row kb-handover mb-4">
            <dt class="col-sm-4">Adresse de connexion</dt><dd class="col-sm-8"><?= e($loginUrl) ?></dd>
            <dt class="col-sm-4">Identifiant</dt><dd class="col-sm-8"><?= e($target['email']) ?></dd>
            <dt class="col-sm-4">Mot de passe temporaire</dt><dd class="col-sm-8"><span class="kb-secret" id="kb-temp-pass"><?= e($tempPassword) ?></span></dd>
        </dl>
        <p class="small text-secondary">À sa première connexion, cette personne devra choisir son propre mot de passe.</p>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" type="button" data-copy="kb-temp-pass"><i data-lucide="copy" aria-hidden="true"></i> Copier le mot de passe</button>
            <button class="btn btn-outline-primary" type="button" data-print><i data-lucide="printer" aria-hidden="true"></i> Imprimer</button>
            <a class="btn btn-primary" href="<?= e(url('/admin/users/' . (int) $target['id'])) ?>">Terminé, voir la fiche</a>
        </div>
    </div>
</div>
