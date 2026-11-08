<?php
/** @var list<array{id:int,name:string}> $people @var array<string,string> $errors @var array<string,mixed> $old */
$this->layout('layouts/app', ['title' => 'Nouvelle conversation', 'breadcrumbs' => [['Messagerie', '/chat'], ['Nouvelle conversation', null]]]);
$selected = array_map('intval', (array) ($old['members'] ?? []));
?>
<div class="kb-page-title"><h1>Nouvelle conversation</h1></div>
<form method="post" action="<?= e(url('/chat/new')) ?>" class="card kb-narrow">
    <div class="card-body">
        <?= csrf_field() ?>
        <fieldset class="mb-3">
            <legend class="form-label fs-6 fw-semibold">Avec qui ?</legend>
            <label class="visually-hidden" for="people-filter">Rechercher un collègue</label>
            <input class="form-control mb-2" type="search" id="people-filter" placeholder="Rechercher un collègue" autocomplete="off" data-directory-filter="chat-people">
            <div class="kb-chat-people" id="chat-people">
                <?php foreach ($people as $p): ?>
                    <label class="kb-check-row" for="m<?= (int) $p['id'] ?>" data-search="<?= e($p['name']) ?>">
                        <input class="form-check-input" type="checkbox" name="members[]" value="<?= (int) $p['id'] ?>" id="m<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $selected, true) ? ' checked' : '' ?>>
                        <span><?= e($p['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="text-secondary small mt-2" id="chat-people-empty" hidden>Aucun collègue ne correspond.</p>
            <?= field_error($errors, 'members') ?>
        </fieldset>
        <div class="mb-3">
            <label class="form-label" for="name">Nom du groupe</label>
            <input class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" maxlength="100" value="<?= e($old['name'] ?? '') ?>" aria-describedby="name-help">
            <div class="form-text" id="name-help">À remplir seulement pour un groupe de plusieurs personnes, par exemple « Chantier Pont ».</div>
            <?= field_error($errors, 'name') ?>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Ouvrir la conversation</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/chat')) ?>">Annuler</a>
        </div>
    </div>
</form>
