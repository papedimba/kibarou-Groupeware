<?php
/** @var list<array<string,mixed>> $departments */
$this->layout('layouts/app', ['title' => 'Services', 'breadcrumbs' => [['Administration', '/admin'], ['Services', null]]]);
?>
<div class="kb-page-title">
    <h1>Services</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/departments/create')) ?>"><i data-lucide="plus" aria-hidden="true"></i> Nouveau service</a>
</div>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Nom</th><th>Code</th><th>Service parent</th><th>Responsable</th><th>Effectif</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($departments as $d): ?>
            <tr>
                <td class="fw-medium"><?= e($d['name']) ?></td>
                <td><?= e($d['code'] ?? '—') ?></td>
                <td><?= e($d['parent_name'] ?? '—') ?></td>
                <td><?= e($d['manager_name'] ?? '—') ?></td>
                <td><?= (int) $d['user_count'] ?></td>
                <td><?= (int) $d['is_active'] === 1 ? '<span class="badge text-bg-success">Actif</span>' : '<span class="badge text-bg-secondary">Inactif</span>' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(url('/admin/departments/' . $d['id'] . '/edit')) ?>" aria-label="Modifier <?= e($d['name']) ?>"><i data-lucide="pencil" aria-hidden="true"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($departments === []): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucun service. Créez le premier.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
