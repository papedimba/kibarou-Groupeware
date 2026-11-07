<?php
/** Erreur 403 / 404 pour un utilisateur connecté. @var int $status @var string $message @var list<array<string,mixed>> $contacts */
$title = $status === 403 ? 'Accès refusé' : 'Page introuvable';
$this->layout('layouts/app', ['title' => $title, 'breadcrumbs' => [[$title, null]]]);
?>
<div class="kb-page-title"><h1><?= e($title) ?></h1></div>
<div class="card col-lg-7">
    <div class="card-body">
        <?php if ($status === 403): ?>
            <p>Votre compte n'a pas les droits nécessaires pour ouvrir cette page. Si vous pensez en avoir besoin pour votre travail, demandez-les à un administrateur :</p>
            <div class="mb-4"><?= $this->partial('partials/admin_contacts', ['contacts' => $contacts]) ?></div>
        <?php else: ?>
            <p class="mb-4">Cette page n'existe pas ou a été déplacée. Vérifiez le lien, ou repartez de l'accueil.</p>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(url('/')) ?>"><i data-lucide="house" aria-hidden="true"></i> Retour à l'accueil</a>
    </div>
</div>
