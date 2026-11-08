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
    <?php if ($mine && !$cancelled): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary" href="<?= e(url("/calendar/events/$id/edit")) ?>">Modifier</a>
            <form method="post" action="<?= e(url("/calendar/events/$id/cancel")) ?>" data-confirm="Annuler « <?= e($event['title']) ?> » ? Les participants seront prévenus." data-confirm-action="Annuler l'évènement" data-confirm-danger>
                <?= csrf_field() ?><button class="btn btn-outline-danger" type="submit">Annuler l'évènement</button>
            </form>
        </div>
    <?php endif; ?>
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
                <div class="card-body d-flex flex-wrap gap-2">
                    <?php foreach (['yes', 'maybe', 'no'] as $r): ?>
                        <form method="post" action="<?= e(url("/calendar/events/$id/respond")) ?>">
                            <?= csrf_field() ?><input type="hidden" name="response" value="<?= $r ?>">
                            <button class="btn <?= $event['response'] === $r ? 'btn-primary' : 'btn-outline-secondary' ?>" type="submit" aria-pressed="<?= $event['response'] === $r ? 'true' : 'false' ?>"><?= e($labels[$r]) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
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
