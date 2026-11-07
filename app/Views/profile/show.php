<?php
/** @var array<string,mixed> $currentUser @var list<array<string,mixed>> $sessions @var string|null $currentSessionId @var array<string,string> $errors @var array<string,mixed> $old */
$this->layout('layouts/app', ['title' => 'Mon profil', 'breadcrumbs' => [['Mon profil', null]]]);
?>
<div class="kb-page-title">
    <h1>Mon profil</h1>
    <a class="btn btn-outline-primary" href="<?= e(url('/profile/password')) ?>"><i data-lucide="lock" aria-hidden="true"></i> Changer mon mot de passe</a>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Coordonnées</div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-4">Nom</dt><dd class="col-8"><?= e($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?></dd>
                    <dt class="col-4">Email</dt><dd class="col-8"><?= e($currentUser['email']) ?></dd>
                    <dt class="col-4">Dernière connexion</dt><dd class="col-8"><?= e(format_date($currentUser['last_login_at'])) ?></dd>
                </dl>
                <form method="post" action="<?= e(url('/profile')) ?>">
                    <?= csrf_field() ?>
                    <label class="form-label" for="phone">Téléphone</label>
                    <div class="input-group">
                        <input class="form-control<?= invalid($errors, 'phone') ?>" id="phone" name="phone" value="<?= e($old['phone'] ?? $currentUser['phone']) ?>" autocomplete="tel">
                        <button class="btn btn-primary" type="submit">Enregistrer</button>
                    </div>
                    <?= field_error($errors, 'phone') ?>
                    <p class="form-text">Nom, email, service et fonction sont gérés par l'administration.</p>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Sessions ouvertes</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle kb-table-stack">
                    <thead><tr><th>Appareil</th><th>Adresse IP</th><th>Dernière activité</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td class="kb-cell-title" title="<?= e($s['user_agent']) ?>"><?= e(device_label((string) $s['user_agent'])) ?></td>
                            <td data-label="Adresse IP"><?= e($s['ip_address']) ?></td>
                            <td data-label="Dernière activité"><?= e(format_date($s['last_activity_at'])) ?></td>
                            <td class="text-end kb-cell-action">
                                <?php if ($s['id'] === $currentSessionId): ?>
                                    <span class="badge text-bg-primary">Cette session</span>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url('/profile/sessions/revoke')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="session_id" value="<?= e($s['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Fermer</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
