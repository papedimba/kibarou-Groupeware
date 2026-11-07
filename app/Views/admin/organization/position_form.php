<?php
/** @var array<string,mixed>|null $position @var array<string,string> $errors @var array<string,mixed> $old */
$isNew = $position === null;
$this->layout('layouts/app', ['title' => $isNew ? 'Nouvelle fonction' : 'Modifier la fonction', 'breadcrumbs' => [['Administration', '/admin'], ['Fonctions', '/admin/positions'], [$isNew ? 'Nouvelle' : 'Modifier', null]]]);
$val = static fn (string $k, mixed $default = '') => $old[$k] ?? ($position[$k] ?? $default);
?>
<div class="kb-page-title"><h1><?= $isNew ? 'Nouvelle fonction' : 'Modifier « ' . e($position['title']) . ' »' ?></h1></div>
<div class="card col-lg-6"><div class="card-body">
    <form method="post" action="<?= e(url($isNew ? '/admin/positions' : '/admin/positions/' . $position['id'])) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="title">Intitulé *</label>
            <input class="form-control<?= invalid($errors, 'title') ?>" id="title" name="title" value="<?= e($val('title')) ?>" required maxlength="150">
            <?= field_error($errors, 'title') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="description">Description</label>
            <textarea class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" rows="3" maxlength="500"><?= e($val('description')) ?></textarea>
            <?= field_error($errors, 'description') ?>
        </div>
        <input type="hidden" name="is_active" value="0">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"<?= (string) $val('is_active', '1') === '1' ? ' checked' : '' ?>>
            <label class="form-check-label" for="is_active">Fonction active</label>
        </div>
        <button class="btn btn-primary" type="submit">Enregistrer</button>
        <a class="btn btn-light" href="<?= e(url('/admin/positions')) ?>">Annuler</a>
    </form>
</div></div>
