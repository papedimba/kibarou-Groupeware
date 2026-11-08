<?php
/** Création ou modification d'un salon. @var array<string,mixed>|null $channel @var array<string,string> $errors @var array<string,mixed> $old */
$editing = $channel !== null;
$title = $editing ? 'Modifier le salon' : 'Nouveau salon';
$this->layout('layouts/app', ['title' => $title, 'breadcrumbs' => [['Messagerie', '/chat'], ['Salons', '/chat/channels'], [$title, null]]]);
$name = $old['name'] ?? ($channel['name'] ?? '');
$description = $old['description'] ?? ($channel['description'] ?? '');
?>
<div class="kb-page-title"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($editing ? '/chat/channels/' . (int) $channel['id'] : '/chat/channels')) ?>" class="card kb-narrow">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="name">Nom du salon</label>
            <input class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" maxlength="80" required value="<?= e($name) ?>" aria-describedby="name-help">
            <div class="form-text" id="name-help">Par exemple « Chantier Pont de Sotuba » ou « Matériel et engins ».</div>
            <?= field_error($errors, 'name') ?>
        </div>
        <div class="mb-3">
            <label class="form-label" for="description">Description <span class="text-secondary">(facultative)</span></label>
            <input class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" maxlength="200" value="<?= e($description) ?>">
            <?= field_error($errors, 'description') ?>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Enregistrer' : 'Créer le salon' ?></button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/chat/channels')) ?>">Annuler</a>
        </div>
    </div>
</form>
