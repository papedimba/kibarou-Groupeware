<?php
/**
 * Sélecteur de statut. @var int|null $targetId null = soi-même @var array<string,mixed>|null $status statut actuel
 * @var string $returnTo @var array<string,string> $errors @var array<string,mixed> $old @var string $idPrefix
 * @var list<array<string,mixed>>|null $team mise à jour groupée : membres de l'équipe (statut du jour dans 'presence')
 */
use App\Modules\Directory\PresenceService;
$idPrefix ??= 'pr';
$team ??= null;
$current = $old['status'] ?? ($status['status'] ?? '');
?>
<form method="post" action="<?= e(url($team !== null ? '/presence/team' : '/presence')) ?>" class="kb-presence-form" data-presence-form>
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
    <?php if ($targetId !== null): ?><input type="hidden" name="user_id" value="<?= (int) $targetId ?>"><?php endif; ?>
    <?php if ($team !== null): ?>
        <fieldset class="mb-3">
            <legend class="h6">Personnes concernées</legend>
            <?php foreach ($team as $m): ?>
                <label class="kb-check-row" for="<?= e($idPrefix) ?>-u<?= (int) $m['id'] ?>">
                    <input class="form-check-input" type="checkbox" name="user_ids[]" value="<?= (int) $m['id'] ?>" id="<?= e($idPrefix) ?>-u<?= (int) $m['id'] ?>"<?= $m['presence'] === null ? ' checked' : '' ?>>
                    <span><?= e($m['first_name'] . ' ' . $m['last_name']) ?>
                        <span class="d-block small"><?= $this->partial('partials/presence_badge', ['status' => $m['presence'], 'online' => false]) ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
            <?= field_error($errors, 'user_ids') ?>
        </fieldset>
    <?php endif; ?>
    <fieldset>
        <legend class="<?= $team !== null ? 'h6' : 'visually-hidden' ?>">Statut du jour</legend>
        <div class="kb-status-choices">
            <?php foreach (PresenceService::LABELS as $code => $label): ?>
                <input class="btn-check" type="radio" name="status" value="<?= e($code) ?>" id="<?= e($idPrefix . '-st-' . $code) ?>"<?= $current === $code ? ' checked' : '' ?> required>
                <label class="btn btn-outline-primary" for="<?= e($idPrefix . '-st-' . $code) ?>"><?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?= field_error($errors, 'status') ?>
    <div class="kb-presence-details row g-2 mt-1" data-presence-details>
        <div class="col-md-6" data-presence-site>
            <label class="form-label" for="<?= e($idPrefix) ?>-site">Chantier</label>
            <input class="form-control<?= invalid($errors, 'site_label') ?>" id="<?= e($idPrefix) ?>-site" name="site_label" maxlength="120" value="<?= e($old['site_label'] ?? ($status['site_label'] ?? '')) ?>">
            <?= field_error($errors, 'site_label') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="<?= e($idPrefix) ?>-until">Jusqu'au (facultatif)</label>
            <input class="form-control<?= invalid($errors, 'until_date') ?>" type="date" id="<?= e($idPrefix) ?>-until" name="until_date" value="<?= e($old['until_date'] ?? ($status['until_date'] ?? '')) ?>">
            <?= field_error($errors, 'until_date') ?>
        </div>
        <div class="col-12">
            <label class="form-label" for="<?= e($idPrefix) ?>-note">Note (facultatif)</label>
            <input class="form-control<?= invalid($errors, 'note') ?>" id="<?= e($idPrefix) ?>-note" name="note" maxlength="160" value="<?= e($old['note'] ?? ($status['note'] ?? '')) ?>">
            <?= field_error($errors, 'note') ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-primary" type="submit">Enregistrer le statut</button>
    </div>
</form>
<?php if ($team === null && $status !== null): ?>
    <form method="post" action="<?= e(url('/presence/clear')) ?>" class="mt-2">
        <?= csrf_field() ?>
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <?php if ($targetId !== null): ?><input type="hidden" name="user_id" value="<?= (int) $targetId ?>"><?php endif; ?>
        <button class="btn btn-link px-0" type="submit">Effacer le statut</button>
    </form>
<?php endif; ?>
