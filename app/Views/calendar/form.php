<?php
/** @var array<string,mixed>|null $event @var array<string,mixed> $values @var list<array<string,mixed>> $people @var list<array<string,mixed>> $departments @var array<string,string> $errors @var array<string,mixed> $old */
$editing = $event !== null;
$v = $old !== [] ? $old + $values : $values;
$title = $editing ? 'Modifier l\'évènement' : 'Nouvel évènement';
$this->layout('layouts/app', ['title' => $title, 'breadcrumbs' => [['Calendrier', '/calendar'], [$title, null]]]);
$selected = array_map('intval', (array) ($v['participants'] ?? []));
$reminders = ['' => 'Aucun rappel', '15' => '15 minutes avant', '60' => '1 heure avant', '1440' => 'La veille'];
?>
<div class="kb-page-title"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($editing ? '/calendar/events/' . (int) $event['id'] : '/calendar/events')) ?>" class="card kb-narrow">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="title">Titre</label>
            <input class="form-control<?= invalid($errors, 'title') ?>" id="title" name="title" maxlength="150" required value="<?= e($v['title'] ?? '') ?>">
            <?= field_error($errors, 'title') ?>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label class="form-label" for="start">Début</label>
                <input class="form-control<?= invalid($errors, 'start') ?>" type="datetime-local" id="start" name="start" required value="<?= e($v['start'] ?? '') ?>">
                <?= field_error($errors, 'start') ?>
            </div>
            <div class="col-sm-6">
                <label class="form-label" for="end">Fin</label>
                <input class="form-control<?= invalid($errors, 'end') ?>" type="datetime-local" id="end" name="end" required value="<?= e($v['end'] ?? '') ?>">
                <?= field_error($errors, 'end') ?>
            </div>
        </div>
        <?php if (!$editing): ?>
            <fieldset class="mb-3">
                <legend class="form-label fs-6 fw-semibold">Répétition</legend>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="visually-hidden" for="repeat">Répétition</label>
                        <select class="form-select" id="repeat" name="repeat">
                            <?php foreach (['' => 'Ne se répète pas', 'weekdays' => 'Chaque jour ouvré (lun.–ven.)', 'weekly' => 'Chaque semaine', 'monthly' => 'Chaque mois (même date)'] as $k => $label): ?>
                                <option value="<?= e($k) ?>"<?= ($v['repeat'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label visually-hidden" for="until">Jusqu'au</label>
                        <input class="form-control<?= invalid($errors, 'until') ?>" type="date" id="until" name="until" value="<?= e($v['until'] ?? '') ?>" aria-describedby="until-help">
                        <div class="form-text" id="until-help">Jusqu'au (un an au plus).</div>
                        <?= field_error($errors, 'until') ?>
                    </div>
                </div>
                <div class="kb-cal-weekdays mt-2" role="group" aria-label="Jours de la semaine (répétition chaque semaine)">
                    <?php foreach ([1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'] as $n => $label): ?>
                        <label class="kb-check-row" for="wd<?= $n ?>"><input class="form-check-input" type="checkbox" name="weekdays[]" value="<?= $n ?>" id="wd<?= $n ?>"<?= in_array((string) $n, (array) ($v['weekdays'] ?? []), true) ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <?= field_error($errors, 'weekdays') ?>
            </fieldset>
        <?php elseif (!empty($event['series_id'])): ?>
            <fieldset class="mb-3">
                <legend class="form-label fs-6 fw-semibold">Cet évènement fait partie d'une série</legend>
                <label class="kb-check-row" for="scope-one"><input class="form-check-input" type="radio" name="scope" value="one" id="scope-one" checked> <span>Modifier cette fois seulement</span></label>
                <label class="kb-check-row" for="scope-following"><input class="form-check-input" type="radio" name="scope" value="following" id="scope-following"> <span>Modifier cette occurrence et les suivantes</span></label>
                <label class="form-label mt-2" for="until">Fin de la série <span class="text-secondary">(prise en compte pour « et les suivantes »)</span></label>
                <input class="form-control<?= invalid($errors, 'until') ?>" type="date" id="until" name="until" value="<?= e($v['until'] ?? '') ?>">
                <?= field_error($errors, 'until') ?>
            </fieldset>
        <?php endif; ?>
        <label class="kb-check-row mb-3" for="all_day">
            <input class="form-check-input" type="checkbox" id="all_day" name="all_day" value="1"<?= (string) ($v['all_day'] ?? '0') === '1' ? ' checked' : '' ?>>
            <span>Journée entière <span class="text-secondary">(les heures sont ignorées)</span></span>
        </label>
        <div class="mb-3">
            <label class="form-label" for="location">Lieu</label>
            <input class="form-control" id="location" name="location" maxlength="150" value="<?= e($v['location'] ?? '') ?>" placeholder="Par exemple : chantier Pont de Sotuba">
        </div>
        <div class="mb-3">
            <label class="form-label" for="description">Description</label>
            <textarea class="form-control" id="description" name="description" rows="3"><?= e($v['description'] ?? '') ?></textarea>
            <div class="form-text">Visible des seuls participants, jamais dans les notifications.</div>
        </div>
        <fieldset class="mb-3">
            <legend class="form-label fs-6 fw-semibold">Invités</legend>
            <label class="visually-hidden" for="people-filter">Rechercher un collègue</label>
            <input class="form-control mb-2" type="search" id="people-filter" placeholder="Rechercher un collègue" autocomplete="off" data-directory-filter="cal-people">
            <div class="kb-chat-people" id="cal-people">
                <?php foreach ($people as $p): ?>
                    <label class="kb-check-row" for="p<?= (int) $p['id'] ?>" data-search="<?= e($p['name']) ?>">
                        <input class="form-check-input" type="checkbox" name="participants[]" value="<?= (int) $p['id'] ?>" id="p<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $selected, true) ? ' checked' : '' ?>>
                        <span><?= e($p['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="text-secondary small mt-2" id="cal-people-empty" hidden>Aucun collègue ne correspond.</p>
        </fieldset>
        <?php if ($departments !== []): ?>
            <fieldset class="mb-3">
                <legend class="form-label fs-6 fw-semibold">Inviter tout un service</legend>
                <div class="kb-chat-people kb-chat-people-sm">
                    <?php foreach ($departments as $d): ?>
                        <label class="kb-check-row" for="d<?= (int) $d['id'] ?>">
                            <input class="form-check-input" type="checkbox" name="departments[]" value="<?= (int) $d['id'] ?>" id="d<?= (int) $d['id'] ?>">
                            <span><?= e($d['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-text">Les membres actuels du service sont ajoutés aux invités.</div>
            </fieldset>
        <?php endif; ?>
        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label class="form-label" for="reminder">Rappel</label>
                <select class="form-select" id="reminder" name="reminder">
                    <?php foreach ($reminders as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= (string) ($v['reminder'] ?? '') === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 d-flex align-items-end">
                <label class="kb-check-row" for="is_private">
                    <input class="form-check-input" type="checkbox" id="is_private" name="is_private" value="1"<?= (string) ($v['is_private'] ?? '0') === '1' ? ' checked' : '' ?>>
                    <span>Privé <span class="text-secondary">(le créneau n'apparaît pas aux autres)</span></span>
                </label>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Enregistrer</button>
            <a class="btn btn-outline-secondary" href="<?= e(url($editing ? '/calendar/events/' . (int) $event['id'] : '/calendar')) ?>">Annuler</a>
        </div>
    </div>
</form>
