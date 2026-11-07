<?php
/** @var array<string,mixed>|null $role @var array<string,list<array<string,mixed>>> $grouped @var list<int> $selected @var array<string,string> $errors @var array<string,mixed> $old */
$isNew = $role === null;
$this->layout('layouts/app', ['title' => $isNew ? 'Nouveau rôle' : 'Modifier le rôle', 'breadcrumbs' => [['Administration', '/admin'], ['Rôles', '/admin/roles'], [$isNew ? 'Nouveau' : 'Modifier', null]]]);
$val = static fn (string $k) => $old[$k] ?? ($role[$k] ?? '');
$checked = isset($old['permissions']) ? array_map('intval', (array) $old['permissions']) : $selected;
$moduleNames = ['admin' => 'Administration', 'users' => 'Utilisateurs', 'organization' => 'Organisation', 'roles' => 'Rôles'];
?>
<div class="kb-page-title"><h1><?= $isNew ? 'Nouveau rôle' : 'Modifier « ' . e($role['name']) . ' »' ?></h1></div>
<form method="post" action="<?= e(url($isNew ? '/admin/roles' : '/admin/roles/' . $role['id'])) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-5"><div class="card"><div class="card-body">
            <?php if ($isNew): ?>
                <div class="mb-3">
                    <label class="form-label" for="slug">Identifiant technique *</label>
                    <input class="form-control<?= invalid($errors, 'slug') ?>" id="slug" name="slug" value="<?= e($val('slug')) ?>" required pattern="[a-z0-9_]{2,50}" aria-describedby="slug-help">
                    <div id="slug-help" class="form-text">Minuscules, chiffres et « _ ». Non modifiable ensuite.</div>
                    <?= field_error($errors, 'slug') ?>
                </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="name">Nom *</label>
                <input class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" required maxlength="100">
                <?= field_error($errors, 'name') ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="description">Description</label>
                <textarea class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" rows="3" maxlength="500"><?= e($val('description')) ?></textarea>
                <?= field_error($errors, 'description') ?>
            </div>
            <button class="btn btn-primary" type="submit">Enregistrer</button>
            <a class="btn btn-light" href="<?= e(url('/admin/roles')) ?>">Annuler</a>
        </div></div></div>
        <div class="col-lg-7"><div class="card"><div class="card-header">Permissions</div><div class="card-body">
            <?= field_error($errors, 'permissions') ?>
            <?php foreach ($grouped as $module => $perms): ?>
                <fieldset class="mb-3">
                    <legend class="h6 text-secondary text-uppercase small"><?= e($moduleNames[$module] ?? $module) ?></legend>
                    <?php foreach ($perms as $p): ?>
                        <label class="kb-check-row" for="perm<?= (int) $p['id'] ?>">
                            <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>" id="perm<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $checked, true) ? ' checked' : '' ?>>
                            <span><?= e($p['description']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
        </div></div></div>
    </div>
</form>
