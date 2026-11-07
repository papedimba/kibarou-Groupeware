<?php
/** @var list<array<string,mixed>> $roles */
$this->layout('layouts/app', ['title' => 'Rôles et permissions', 'breadcrumbs' => [['Administration', '/admin'], ['Rôles', null]]]);
?>
<div class="kb-page-title">
    <h1>Rôles et permissions</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/roles/create')) ?>"><i data-lucide="plus" aria-hidden="true"></i> Nouveau rôle</a>
</div>
<div class="card"><div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Rôle</th><th>Utilisateurs</th><th>Permissions</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
            <tr>
                <td>
                    <span class="fw-medium"><?= e($r['name']) ?></span>
                    <?php if ((int) $r['is_system'] === 1): ?><span class="badge text-bg-light ms-1">système</span><?php endif; ?>
                    <div class="small text-secondary"><?= e($r['description'] ?? '') ?></div>
                </td>
                <td><?= (int) $r['user_count'] ?></td>
                <td><?= $r['slug'] === 'super_admin' ? 'Toutes' : (int) $r['permission_count'] ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($r['slug'] !== 'super_admin'): ?>
                        <a class="btn btn-sm btn-light" href="<?= e(url('/admin/roles/' . $r['id'] . '/edit')) ?>" aria-label="Modifier <?= e($r['name']) ?>"><i data-lucide="pencil" aria-hidden="true"></i></a>
                    <?php endif; ?>
                    <?php if ((int) $r['is_system'] === 0): ?>
                        <form class="d-inline" method="post" action="<?= e(url('/admin/roles/' . $r['id'] . '/delete')) ?>" data-confirm="Supprimer ce rôle ?">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-light text-danger" type="submit" aria-label="Supprimer <?= e($r['name']) ?>"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>
