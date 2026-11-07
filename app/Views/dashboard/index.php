<?php
/**
 * @var array<string,mixed> $currentUser @var array<string,mixed> $me
 * @var array<string,mixed>|null $alerts @var list<array<string,mixed>> $contacts
 */
$this->layout('layouts/app', ['title' => 'Accueil']);
$value = static fn (?string $v): string => $v !== null && $v !== '' ? e($v) : '<span class="text-secondary">Non renseigné</span>';
?>
<div class="kb-page-title">
    <h1>Bonjour <?= e($currentUser['first_name']) ?></h1>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <?php if ($alerts !== null): ?>
            <section class="card mb-4" aria-labelledby="admin-title">
                <div class="card-header" id="admin-title">Administration</div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php if (can('users.manage')): ?><a class="btn btn-primary" href="<?= e(url('/admin/users/create')) ?>"><i data-lucide="user-plus" aria-hidden="true"></i> Nouvel utilisateur</a><?php endif; ?>
                        <?php if (can('users.view')): ?><a class="btn btn-outline-primary" href="<?= e(url('/admin/users')) ?>"><i data-lucide="users" aria-hidden="true"></i> Utilisateurs</a><?php endif; ?>
                        <?php if (can('audit.view')): ?><a class="btn btn-outline-primary" href="<?= e(url('/admin/audit')) ?>"><i data-lucide="scroll-text" aria-hidden="true"></i> Journal d'audit</a><?php endif; ?>
                    </div>
                    <ul class="list-unstyled mb-0 kb-alert-list">
                        <li><?= (int) $alerts['never_logged'] ?> compte(s) pas encore connecté(s)</li>
                        <li class="<?= (int) $alerts['failed_logins'] > 20 ? 'text-alert fw-semibold' : '' ?>"><?= (int) $alerts['failed_logins'] ?> échec(s) de connexion ces dernières 24 h</li>
                        <li class="<?= (int) $alerts['failed_mails'] > 0 ? 'text-alert fw-semibold' : '' ?>"><?= (int) $alerts['failed_mails'] ?> email(s) en échec</li>
                    </ul>
                </div>
            </section>
        <?php endif; ?>
        <section class="card" aria-labelledby="me-title">
            <div class="card-header" id="me-title">Mon espace</div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-sm-5">Service</dt><dd class="col-sm-7"><?= $value($me['department'] ?? null) ?></dd>
                    <dt class="col-sm-5">Fonction</dt><dd class="col-sm-7"><?= $value($me['position'] ?? null) ?></dd>
                    <dt class="col-sm-5">Responsable</dt><dd class="col-sm-7"><?= $value($me['manager'] ?? null) ?></dd>
                    <dt class="col-sm-5">Dernière connexion</dt><dd class="col-sm-7"><?= e(format_date($currentUser['last_login_at'])) ?></dd>
                </dl>
                <a class="btn btn-outline-primary" href="<?= e(url('/profile')) ?>"><i data-lucide="user" aria-hidden="true"></i> Mon profil et mes sessions</a>
            </div>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card mb-4" aria-labelledby="help-title">
            <div class="card-header" id="help-title">Besoin d'aide ?</div>
            <div class="card-body">
                <p>Pour un accès, un mot de passe ou un problème de compte, contactez :</p>
                <?= $this->partial('partials/admin_contacts', ['contacts' => $contacts]) ?>
            </div>
        </section>
        <p class="text-secondary">Bientôt : messagerie, calendrier, tâches, projets, documents, annonces, demandes et annuaire.</p>
    </div>
</div>
