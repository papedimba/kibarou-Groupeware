<?php
/** Abonnement à son agenda. @var bool $hasFeed @var string|null $feedUrl */
$this->layout('layouts/app', ['title' => 'Mon agenda ailleurs', 'breadcrumbs' => [['Mon profil', '/profile'], ['Mon agenda ailleurs', null]]]);
?>
<div class="kb-page-title"><h1>Mon agenda dans Outlook, Google ou mon téléphone</h1></div>
<section class="card kb-narrow">
    <div class="card-body">
        <p>Un lien secret permet d'afficher vos évènements de l'intranet dans votre application d'agenda, en lecture seule (des 30 derniers jours à l'année à venir). Ne le partagez pas : il donne accès à votre agenda sans mot de passe.</p>
        <?php if ($feedUrl): ?>
            <div class="alert alert-success" role="status">
                <p class="mb-2"><strong>Votre lien</strong> (copiez-le maintenant : il ne sera plus affiché) :</p>
                <label class="visually-hidden" for="feed-url">Lien d'abonnement</label>
                <input class="form-control" id="feed-url" value="<?= e($feedUrl) ?>" readonly>
            </div>
        <?php elseif ($hasFeed): ?>
            <p class="text-secondary">Un lien est déjà actif. Si vous l'avez perdu ou s'il a été partagé par erreur, créez-en un nouveau : l'ancien cessera de fonctionner.</p>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/profile/calendar/feed')) ?>"<?= $hasFeed ? ' data-confirm="Créer un nouveau lien ? L\'ancien cessera de fonctionner partout où il est utilisé." data-confirm-action="Créer un nouveau lien"' : '' ?>>
            <?= csrf_field() ?>
            <button class="btn btn-primary" type="submit"><?= $hasFeed ? 'Créer un nouveau lien' : 'Créer mon lien d\'abonnement' ?></button>
        </form>
        <h2 class="h6 mt-4">Où le coller</h2>
        <ul class="mb-0">
            <li><strong>Outlook</strong> : Ajouter un calendrier › S'abonner à partir du web.</li>
            <li><strong>Google Agenda</strong> : Autres agendas › + › À partir de l'URL.</li>
            <li><strong>iPhone</strong> : Réglages › Calendrier › Comptes › Ajouter un compte › Autre › Calendrier avec abonnement.</li>
        </ul>
    </div>
</section>
