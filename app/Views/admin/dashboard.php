<?php
/** @var array<string,mixed> $stats @var string|null $cronLast @var bool $chatStorageWarn @var list<array<string,mixed>> $recent */
$this->layout('layouts/app', ['title' => 'Administration', 'breadcrumbs' => [['Administration', null]]]);
$cronLate = $cronLast === null || strtotime($cronLast . ' UTC') < time() - 1800;
$cards = [
    ['users', 'Utilisateurs actifs', $stats['active_users'], false],
    ['user-x', 'Comptes désactivés', $stats['disabled_users'], false],
    ['activity', 'Connectés (15 min)', $stats['online_users'], false],
    ['building-2', 'Services', $stats['departments'], false],
    ['shield-alert', 'Échecs de connexion (24 h)', $stats['failed_logins_24h'], (int) $stats['failed_logins_24h'] > 20],
    ['mail-warning', 'Emails en échec', $stats['failed_mails'], (int) $stats['failed_mails'] > 0],
    ['paperclip', 'Pièces jointes de la messagerie', format_bytes((int) $stats['chat_storage']), $chatStorageWarn],
];
?>
<div class="kb-page-title"><h1>Administration</h1></div>
<?php if ($cronLate): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-center" role="alert">
        <i data-lucide="clock-alert" class="text-alert" aria-hidden="true"></i>
        <span>La tâche planifiée (CRON) ne s'est pas exécutée depuis plus de 30 minutes<?= $cronLast ? ' (dernière : ' . e(format_date($cronLast)) . ')' : '' ?>. Les emails ne partent pas. Voir docs/DEPLOYMENT.md.</span>
    </div>
<?php endif; ?>
<?php if ($chatStorageWarn): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-center" role="alert">
        <i data-lucide="hard-drive" class="text-alert" aria-hidden="true"></i>
        <span>Les pièces jointes de la messagerie occupent <?= e(format_bytes((int) $stats['chat_storage'])) ?> : l'espace disque de l'hébergement approche de la limite fixée. Vérifiez la place disponible chez l'hébergeur.</span>
    </div>
<?php endif; ?>
<div class="row g-3 mb-4">
    <?php foreach ($cards as [$icon, $label, $value, $alert]): ?>
        <div class="col-6 col-lg-4 col-xl-2">
            <div class="card h-100"><div class="card-body kb-stat">
                <div class="kb-stat-icon<?= $alert ? ' alert' : '' ?>"><i data-lucide="<?= e($icon) ?>" aria-hidden="true"></i></div>
                <div><div class="kb-stat-value"><?= e(is_string($value) && !ctype_digit($value) ? $value : (string) (int) $value) ?></div><div class="kb-stat-label"><?= e($label) ?></div></div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($recent !== []): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between">Activité récente <a class="small" href="<?= e(url('/admin/audit')) ?>">Tout voir</a></div>
        <?= $this->partial('admin/_audit_table', ['rows' => $recent]) ?>
    </div>
<?php endif; ?>
