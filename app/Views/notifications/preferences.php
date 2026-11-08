<?php
/** @var array<string,array{label:string,email:string,browser:bool,mandatory:bool}> $prefs */
$this->layout('layouts/app', ['title' => 'Préférences de notification', 'breadcrumbs' => [['Mon profil', '/profile'], ['Notifications', null]]]);
$modes = ['immediate' => 'Email immédiat', 'digest' => 'Récapitulatif quotidien (7 h)', 'off' => 'Pas d\'email'];
?>
<div class="kb-page-title">
    <h1>Préférences de notification</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/notifications')) ?>"><i data-lucide="bell" aria-hidden="true"></i> Mes notifications</a>
</div>
<div class="row g-4">
    <div class="col-lg-8">
        <form method="post" action="<?= e(url('/profile/notifications')) ?>" class="card">
            <?= csrf_field() ?>
            <h2 class="card-header h6 m-0">Par type de notification</h2>
            <ul class="list-unstyled m-0 kb-prefs">
                <?php foreach ($prefs as $type => $p): ?>
                    <?php $id = 'pref-' . str_replace('.', '-', $type); ?>
                    <li class="kb-pref">
                        <span class="kb-pref-label" id="<?= e($id) ?>-label"><?= e($p['label']) ?></span>
                        <?php if ($p['mandatory']): ?>
                            <span class="text-secondary small">Alerte de sécurité, toujours envoyée par email</span>
                        <?php else: ?>
                            <div class="kb-pref-controls">
                                <label class="visually-hidden" for="<?= e($id) ?>-email">Email pour « <?= e($p['label']) ?> »</label>
                                <select class="form-select" id="<?= e($id) ?>-email" name="email[<?= e($type) ?>]">
                                    <?php foreach ($modes as $value => $text): ?>
                                        <option value="<?= e($value) ?>"<?= $p['email'] === $value ? ' selected' : '' ?>><?= e($text) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="kb-check-row" for="<?= e($id) ?>-browser">
                                    <input class="form-check-input" type="checkbox" id="<?= e($id) ?>-browser" name="browser[<?= e($type) ?>]" value="1"<?= $p['browser'] ? ' checked' : '' ?>>
                                    <span>Alerte du navigateur</span>
                                </label>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body border-top"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
        </form>
    </div>
    <div class="col-lg-4">
        <section class="card" aria-labelledby="alerts-title">
            <h2 class="card-header h6 m-0" id="alerts-title">Alertes du navigateur</h2>
            <div class="card-body">
                <p class="small">Quand l'intranet est ouvert, une alerte s'affiche à l'arrivée d'une notification, même si vous êtes dans un autre onglet.</p>
                <button class="btn btn-outline-primary" type="button" data-enable-alerts><i data-lucide="bell-ring" aria-hidden="true"></i> Activer les alertes du navigateur</button>
                <p class="small text-secondary mt-2 mb-0" data-alerts-status role="status"></p>
            </div>
        </section>
    </div>
</div>
