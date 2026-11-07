<?php
/** @var list<array<string,mixed>> $positions */
$this->layout('layouts/app', ['title' => 'Fonctions', 'breadcrumbs' => [['Administration', '/admin'], ['Fonctions', null]]]);
?>
<div class="kb-page-title">
    <h1>Fonctions</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/positions/create')) ?>"><i data-lucide="plus" aria-hidden="true"></i> Nouvelle fonction</a>
</div>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Intitulé</th><th>Description</th><th>Effectif</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($positions as $p): ?>
            <tr>
                <td class="fw-medium"><?= e($p['title']) ?></td>
                <td class="small text-secondary"><?= e($p['description'] ?? '') ?></td>
                <td><?= (int) $p['user_count'] ?></td>
                <td><?= (int) $p['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(url('/admin/positions/' . $p['id'] . '/edit')) ?>" aria-label="Modifier <?= e($p['title']) ?>"><i data-lucide="pencil" aria-hidden="true"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($positions === []): ?><tr><td colspan="5" class="text-center text-secondary py-4">Aucune fonction.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
