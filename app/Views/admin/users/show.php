<?php
/**
 * @var array<string,mixed> $target @var list<string> $roles @var list<array<string,mixed>> $sessions
 * @var array<string,mixed>|null $department @var array<string,mixed>|null $position @var array<string,mixed>|null $manager
 * @var list<array<string,mixed>> $audit @var array<string,mixed> $currentUser
 */
$name = $target['first_name'] . ' ' . $target['last_name'];
$this->layout('layouts/app', ['title' => $name, 'breadcrumbs' => [['Administration', '/admin'], ['Utilisateurs', '/admin/users'], [$name, null]]]);
$active = $target['status'] === 'active';
$id = (int) $target['id'];
?>
<div class="kb-page-title">
    <div class="d-flex align-items-center gap-3">
        <span class="kb-avatar" aria-hidden="true"><?= e(initials((string) $target['first_name'], (string) $target['last_name'])) ?></span>
        <div>
            <h1><?= e($name) ?></h1>
            <div class="text-secondary small"><?= e($target['email']) ?> · <?= $active ? '<span class="text-success">Actif</span>' : '<span class="text-alert">Désactivé</span>' ?></div>
        </div>
    </div>
    <?php if (can('users.manage')): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="<?= e(url("/admin/users/$id/edit")) ?>"><i data-lucide="pencil" aria-hidden="true"></i> Modifier</a>
            <form method="post" action="<?= e(url("/admin/users/$id/reset-link")) ?>" data-confirm="Envoyer un lien de réinitialisation du mot de passe à cet utilisateur ?">
                <?= csrf_field() ?>
                <button class="btn btn-outline-secondary" type="submit"><i data-lucide="key-round" aria-hidden="true"></i> Lien de réinitialisation</button>
            </form>
            <?php if ($id !== (int) $currentUser['id']): ?>
                <form method="post" action="<?= e(url("/admin/users/$id/status")) ?>" data-confirm="<?= $active ? 'Désactiver ce compte ? Ses sessions seront fermées.' : 'Réactiver ce compte ?' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="status" value="<?= $active ? 'disabled' : 'active' ?>">
                    <button class="btn <?= $active ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit">
                        <i data-lucide="<?= $active ? 'user-x' : 'user-check' ?>" aria-hidden="true"></i> <?= $active ? 'Désactiver' : 'Activer' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card"><div class="card-header">Informations</div><div class="card-body">
            <dl class="row mb-0">
                <dt class="col-5">Téléphone</dt><dd class="col-7"><?= e($target['phone'] ?: '—') ?></dd>
                <dt class="col-5">Service</dt><dd class="col-7"><?= e($department['name'] ?? '—') ?></dd>
                <dt class="col-5">Fonction</dt><dd class="col-7"><?= e($position['title'] ?? '—') ?></dd>
                <dt class="col-5">Responsable</dt><dd class="col-7"><?= $manager ? e($manager['first_name'] . ' ' . $manager['last_name']) : '—' ?></dd>
                <dt class="col-5">Rôles</dt><dd class="col-7"><?= e(implode(', ', $roles) ?: '—') ?></dd>
                <dt class="col-5">Créé le</dt><dd class="col-7"><?= e(format_date($target['created_at'])) ?></dd>
                <dt class="col-5">Dernière connexion</dt><dd class="col-7"><?= e(format_date($target['last_login_at'])) ?></dd>
                <dt class="col-5">Mot de passe changé</dt><dd class="col-7"><?= e(format_date($target['password_changed_at'])) ?></dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-7">
        <?php if (can('sessions.manage')): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    Sessions ouvertes (<?= count($sessions) ?>)
                    <?php if ($sessions !== []): ?>
                        <form method="post" action="<?= e(url("/admin/users/$id/sessions/revoke")) ?>" data-confirm="Fermer toutes les sessions de cet utilisateur ?">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger" type="submit">Tout fermer</button>
                        </form>
                    <?php endif; ?>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($sessions as $s): ?>
                        <li class="list-group-item small"><span class="fw-medium"><?= e(device_label((string) $s['user_agent'])) ?></span> · <?= e($s['ip_address']) ?> · <?= e(format_date($s['last_activity_at'])) ?></li>
                    <?php endforeach; ?>
                    <?php if ($sessions === []): ?><li class="list-group-item small text-secondary">Aucune session ouverte.</li><?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($audit !== []): ?>
            <div class="card"><div class="card-header">Historique</div><?= $this->partial('admin/_audit_table', ['rows' => $audit]) ?></div>
        <?php endif; ?>
    </div>
</div>
