<?php
/** @var array<string,mixed> $event @var list<array<string,mixed>> $participants @var string $when @var bool $mine @var string|null $endLabel */
$this->layout('layouts/app', ['title' => $event['title'], 'breadcrumbs' => [['Calendrier', '/calendar'], [$event['title'], null]]]);
$labels = ['yes' => 'J\'y serai', 'maybe' => 'Peut-être', 'no' => 'Je n\'y serai pas', 'pending' => 'Pas encore répondu'];
$reminders = ['' => 'Aucun rappel', '15' => '15 minutes avant', '60' => '1 heure avant', '1440' => 'La veille'];
$id = (int) $event['id'];
$cancelled = $event['status'] === 'cancelled';
?>
<div class="kb-page-title">
    <div>
        <h1><?= e($event['title']) ?></h1>
        <p class="text-secondary m-0"><?= e($when) ?><?= $endLabel ? ' – ' . e($endLabel) : '' ?><?= $event['location'] ? ' · ' . e($event['location']) : '' ?><?= (int) $event['is_private'] === 1 ? ' · Privé' : '' ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-light" href="<?= e(url("/calendar/events/$id.ics")) ?>"><i data-lucide="download" aria-hidden="true"></i> Ajouter à mon agenda (.ics)</a>
    <?php if ($mine && !$cancelled): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/calendar/events/$id/edit")) ?>">Modifier</a>
            <form method="post" action="<?= e(url("/calendar/events/$id/cancel")) ?>" data-confirm="Annuler « <?= e($event['title']) ?> »<?= $event['series_id'] ? ' (cette fois seulement)' : '' ?> ? Les participants seront prévenus." data-confirm-action="Annuler" data-confirm-danger>
                <?= csrf_field() ?><button class="btn btn-outline-danger" type="submit"><?= $event['series_id'] ? 'Annuler cette fois' : 'Annuler l\'évènement' ?></button>
            </form>
            <?php if ($event['series_id']): ?>
                <form method="post" action="<?= e(url("/calendar/events/$id/cancel")) ?>" data-confirm="Annuler cette occurrence et toutes les suivantes de la série ? Les participants seront prévenus." data-confirm-action="Annuler la suite" data-confirm-danger>
                    <?= csrf_field() ?><input type="hidden" name="scope" value="following"><button class="btn btn-outline-danger" type="submit">Annuler celle-ci et les suivantes</button>
                </form>
            <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
<?php if ($cancelled): ?><div class="alert alert-warning" role="status">Cet évènement est annulé.</div><?php endif; ?>
<div class="row g-4">
    <div class="col-lg-7">
        <?php if (!empty($event['description'])): ?>
            <section class="card mb-4"><div class="card-body"><?= linkify((string) $event['description']) ?></div></section>
        <?php endif; ?>
        <?php if (!$mine && !$cancelled): ?>
            <section class="card mb-4" aria-labelledby="respond-title">
                <h2 class="card-header h6 m-0" id="respond-title">Votre réponse</h2>
                <form class="card-body" method="post" action="<?= e(url("/calendar/events/$id/respond")) ?>">
                    <?= csrf_field() ?>
                    <fieldset class="kb-status-choices mb-2">
                        <legend class="visually-hidden">Votre réponse</legend>
                        <?php foreach (['yes', 'maybe', 'no'] as $r): ?>
                            <input class="btn-check" type="radio" name="response" value="<?= $r ?>" id="resp-<?= $r ?>"<?= $event['response'] === $r ? ' checked' : '' ?> required>
                            <label class="btn btn-outline-secondary" for="resp-<?= $r ?>"><?= e($labels[$r]) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <?php if ($event['series_id']): ?>
                        <label class="kb-check-row" for="resp-one"><input class="form-check-input" type="checkbox" name="scope" value="one" id="resp-one"> <span>Pour cette date seulement <span class="text-secondary">(sinon : cette date et les suivantes de la série)</span></span></label>
                    <?php endif; ?>
                    <button class="btn btn-primary mt-2" type="submit">Répondre</button>
                </form>
            </section>
        <?php endif; ?>
        <section class="card" aria-labelledby="reminder-title">
            <h2 class="card-header h6 m-0" id="reminder-title">Mon rappel</h2>
            <form class="card-body d-flex flex-wrap gap-2 align-items-end" method="post" action="<?= e(url("/calendar/events/$id/reminder")) ?>">
                <?= csrf_field() ?>
                <div>
                    <label class="form-label" for="reminder">Me rappeler</label>
                    <select class="form-select" id="reminder" name="reminder">
                        <?php foreach ($reminders as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= (string) ($event['my_reminder'] ?? '') === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-outline-primary" type="submit">Enregistrer</button>
            </form>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card" aria-labelledby="people-title">
            <h2 class="card-header h6 m-0" id="people-title">Participants (<?= count($participants) ?>)</h2>
            <ul class="kb-cal-people">
                <?php foreach ($participants as $p): ?>
                    <li><?= avatar($p) ?> <span><?= e($p['first_name'] . ' ' . $p['last_name']) ?><?= (int) $p['user_id'] === (int) $event['organizer_id'] ? ' <span class="text-secondary">(organisateur)</span>' : '' ?></span>
                        <span class="kb-cal-resp kb-cal-resp-<?= e($p['response']) ?>"><?= e($labels[$p['response']]) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</div>
