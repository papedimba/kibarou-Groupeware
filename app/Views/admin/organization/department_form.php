<?php
/** @var array<string,mixed>|null $dept @var list<array<string,mixed>> $parents @var list<array<string,mixed>> $managers @var array<string,string> $errors @var array<string,mixed> $old */
$isNew = $dept === null;
$this->layout('layouts/app', ['title' => $isNew ? 'Nouveau service' : 'Modifier le service', 'breadcrumbs' => [['Administration', '/admin'], ['Services', '/admin/departments'], [$isNew ? 'Nouveau' : 'Modifier', null]]]);
$val = static fn (string $k, mixed $default = '') => $old[$k] ?? ($dept[$k] ?? $default);
?>
<div class="kb-page-title"><h1><?= $isNew ? 'Nouveau service' : 'Modifier « ' . e($dept['name']) . ' »' ?></h1></div>
<div class="card col-lg-8"><div class="card-body">
    <form method="post" action="<?= e(url($isNew ? '/admin/departments' : '/admin/departments/' . $dept['id'])) ?>" class="row g-3" novalidate>
        <?= csrf_field() ?>
        <div class="col-md-8">
            <label class="form-label" for="name">Nom *</label>
            <input class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" required maxlength="150">
            <?= field_error($errors, 'name') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="code">Code</label>
            <input class="form-control<?= invalid($errors, 'code') ?>" id="code" name="code" value="<?= e($val('code')) ?>" maxlength="30">
            <?= field_error($errors, 'code') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="parent_id">Service parent</label>
            <select class="form-select<?= invalid($errors, 'parent_id') ?>" id="parent_id" name="parent_id">
                <option value="">— Aucun —</option>
                <?php foreach ($parents as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (string) $val('parent_id') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
            <?= field_error($errors, 'parent_id') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="manager_user_id">Responsable</label>
            <select class="form-select<?= invalid($errors, 'manager_user_id') ?>" id="manager_user_id" name="manager_user_id">
                <option value="">— Aucun —</option>
                <?php foreach ($managers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (string) $val('manager_user_id') === (string) $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
            </select>
            <?= field_error($errors, 'manager_user_id') ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="description">Description</label>
            <textarea class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" rows="3" maxlength="500"><?= e($val('description')) ?></textarea>
            <?= field_error($errors, 'description') ?>
        </div>
        <div class="col-12">
            <input type="hidden" name="is_active" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= (string) $val('is_active', '1') === '1' ? ' checked' : '' ?>>
                <label class="form-check-label" for="is_active">Service actif</label>
            </div>
        </div>
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary" type="submit">Enregistrer</button>
            <a class="btn btn-light" href="<?= e(url('/admin/departments')) ?>">Annuler</a>
        </div>
    </form>
</div></div>
