<?php
/** @var App\Core\Paginator $users @var array<string,mixed> $filters @var list<array<string,mixed>> $departments */
$this->layout('layouts/app', ['title' => 'Utilisateurs', 'breadcrumbs' => [['Administration', '/admin'], ['Utilisateurs', null]]]);
?>
<div class="kb-page-title">
    <h1>Utilisateurs</h1>
    <?php if (can('users.manage')): ?>
        <a class="btn btn-primary" href="<?= e(url('/admin/users/create')) ?>"><i data-lucide="user-plus" aria-hidden="true"></i> Nouvel utilisateur</a>
    <?php endif; ?>
</div>
<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-lg-4">
        <label class="visually-hidden" for="q">Recherche</label>
        <input class="form-control" type="search" id="q" name="q" placeholder="Nom, prénom ou email" value="<?= e($filters['q']) ?>">
    </div>
    <div class="col-6 col-lg-3">
        <label class="visually-hidden" for="department_id">Service</label>
        <select class="form-select" id="department_id" name="department_id" data-autosubmit>
            <option value="">Tous les services</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>"<?= (int) $filters['department_id'] === (int) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-lg-2">
        <label class="visually-hidden" for="status">Statut</label>
        <select class="form-select" id="status" name="status" data-autosubmit>
            <option value="">Tous statuts</option>
            <option value="active"<?= $filters['status'] === 'active' ? ' selected' : '' ?>>Actifs</option>
            <option value="disabled"<?= $filters['status'] === 'disabled' ? ' selected' : '' ?>>Désactivés</option>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit"><i data-lucide="search" aria-hidden="true"></i> Rechercher</button></div>
</form>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle kb-table-stack">
            <thead><tr><th>Nom</th><th>Service</th><th>Fonction</th><th>Rôles</th><th>Statut</th><th>Dernière connexion</th></tr></thead>
            <tbody>
            <?php foreach ($users->items as $u): ?>
                <tr>
                    <td class="kb-cell-title">
                        <a class="fw-medium text-decoration-none" href="<?= e(url('/admin/users/' . $u['id'])) ?>"><?= e($u['last_name'] . ' ' . $u['first_name']) ?></a>
                        <div class="small text-secondary"><?= e($u['email']) ?></div>
                    </td>
                    <td data-label="Service"><?= e($u['department_name'] ?? '—') ?></td>
                    <td data-label="Fonction"><?= e($u['position_title'] ?? '—') ?></td>
                    <td data-label="Rôles" class="small"><?= e($u['role_names'] ?? '—') ?></td>
                    <td data-label="Statut"><?= $u['status'] === 'active' ? '<span class="badge text-bg-success">Actif</span>' : '<span class="badge text-bg-secondary">Désactivé</span>' ?></td>
                    <td data-label="Dernière connexion" class="small"><?= e(format_date($u['last_login_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($users->items === []): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucun utilisateur trouvé.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->partial('partials/pagination', ['pager' => $users, 'query' => array_filter($filters)]) ?>
