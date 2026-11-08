<?php
/**
 * Disponibilités. @var DateTimeImmutable $start @var array<int,array<string,array<string,mixed>>> $grid @var list<int> $selected
 * @var list<array<string,mixed>> $people @var string|null $error @var array<int,string> $names
 */
$this->layout('layouts/app', ['title' => 'Disponibilités', 'breadcrumbs' => [['Calendrier', '/calendar'], ['Disponibilités', null]]]);
$days = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
$query = static fn (string $date) => url('/calendar/availability?date=' . $date . implode('', array_map(static fn ($id) => '&users[]=' . (int) $id, $selected)));
$first = array_key_first($grid);
?>
<div class="kb-page-title"><h1>Disponibilités</h1></div>
<p class="text-secondary kb-narrow">Créneaux occupés et absences des personnes choisies, sans le détail de leurs évènements.</p>
<?php if ($error): ?><div class="alert alert-warning" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="kb-cal-toolbar">
    <nav class="kb-cal-nav" aria-label="Période">
        <a class="btn btn-light" href="<?= e($query($start->modify('-7 days')->format('Y-m-d'))) ?>" aria-label="Sept jours plus tôt"><i data-lucide="chevron-left" aria-hidden="true"></i></a>
        <a class="btn btn-light" href="<?= e($query((new DateTimeImmutable('today', $start->getTimezone()))->format('Y-m-d'))) ?>">Aujourd'hui</a>
        <a class="btn btn-light" href="<?= e($query($start->modify('+7 days')->format('Y-m-d'))) ?>" aria-label="Sept jours plus tard"><i data-lucide="chevron-right" aria-hidden="true"></i></a>
    </nav>
</div>
<div class="kb-avail-wrap">
    <table class="table kb-avail">
        <caption class="visually-hidden">Disponibilités sur sept jours</caption>
        <thead><tr><th scope="col">Personne</th>
            <?php foreach (array_keys($grid[$first]) as $key): $d = new DateTimeImmutable($key); ?>
                <th scope="col"><?= e($days[(int) $d->format('w')] . ' ' . $d->format('d/m')) ?></th>
            <?php endforeach; ?></tr></thead>
        <tbody>
            <?php foreach ($grid as $uid => $cells): ?>
                <tr><th scope="row"><?= e($names[$uid] ?? '') ?></th>
                    <?php foreach ($cells as $cell): ?>
                        <td class="<?= !empty($cell['presence']['off']) ? 'kb-avail-off' : '' ?>">
                            <?php if ($cell['presence']): ?><span class="kb-cal-presence"><?= e($cell['presence']['label']) ?></span><?php endif; ?>
                            <?php foreach ($cell['busy'] as $b): ?><span class="kb-avail-busy"><?= e($b) ?></span><?php endforeach; ?>
                            <?php if (!$cell['busy'] && !$cell['presence']): ?><span class="kb-avail-free">Libre</span><?php endif; ?>
                        </td>
                    <?php endforeach; ?></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<form method="get" action="<?= e(url('/calendar/availability')) ?>" class="card kb-narrow mt-4">
    <div class="card-body">
        <input type="hidden" name="date" value="<?= e($start->format('Y-m-d')) ?>">
        <fieldset>
            <legend class="form-label fs-6 fw-semibold">Personnes (dix au plus)</legend>
            <label class="visually-hidden" for="avail-filter">Rechercher un collègue</label>
            <input class="form-control mb-2" type="search" id="avail-filter" placeholder="Rechercher un collègue" autocomplete="off" data-directory-filter="avail-people">
            <div class="kb-chat-people" id="avail-people">
                <?php foreach ($people as $p): ?>
                    <label class="kb-check-row" for="a<?= (int) $p['id'] ?>" data-search="<?= e($p['name']) ?>">
                        <input class="form-check-input" type="checkbox" name="users[]" value="<?= (int) $p['id'] ?>" id="a<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $selected, true) ? ' checked' : '' ?>>
                        <span><?= e($p['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <button class="btn btn-primary mt-3" type="submit">Afficher</button>
    </div>
</form>
