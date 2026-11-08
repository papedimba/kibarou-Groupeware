<?php
/**
 * Agenda personnel. @var string $view @var DateTimeImmutable $date @var array<string,array<string,mixed>> $days
 * @var string $prev @var string $next @var int $firstHour @var int $lastHour
 */
$months = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$keys = array_keys($days);
$first = $days[$keys[0]]['date'];
$heading = match ($view) {
    'month' => ucfirst($months[(int) $date->format('n')]) . ' ' . $date->format('Y'),
    'week' => 'Semaine du ' . $first->format('d') . ' ' . $months[(int) $first->format('n')] . ' ' . $first->format('Y'),
    default => 'À partir du ' . $date->format('d/m/Y'),
};
$this->layout('layouts/app', ['title' => 'Calendrier', 'breadcrumbs' => [['Calendrier', null]]]);
$link = static fn (string $v, string $d) => url('/calendar?view=' . $v . '&date=' . $d);
$today = (new DateTimeImmutable('today', $date->getTimezone()))->format('Y-m-d');
?>
<div class="kb-page-title">
    <h1><?= e($heading) ?></h1>
    <a class="btn btn-primary" href="<?= e(url('/calendar/events/new?date=' . $date->format('Y-m-d'))) ?>"><i data-lucide="plus" aria-hidden="true"></i> Nouvel évènement</a>
</div>
<div class="kb-cal-toolbar">
    <nav class="kb-cal-nav" aria-label="Période">
        <a class="btn btn-light" href="<?= e($link($view, $prev)) ?>" aria-label="Période précédente"><i data-lucide="chevron-left" aria-hidden="true"></i></a>
        <a class="btn btn-light" href="<?= e($link($view, $today)) ?>">Aujourd'hui</a>
        <a class="btn btn-light" href="<?= e($link($view, $next)) ?>" aria-label="Période suivante"><i data-lucide="chevron-right" aria-hidden="true"></i></a>
    </nav>
    <nav class="kb-tabs kb-cal-views" aria-label="Affichage">
        <?php foreach (['week' => 'Semaine', 'month' => 'Mois', 'list' => 'Liste'] as $v => $label): ?>
            <a href="<?= e($link($v, $date->format('Y-m-d'))) ?>"<?= $v === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(url('/calendar/availability')) ?>">Disponibilités</a>
    </nav>
</div>
<?= $this->partial('calendar/_' . $view, ['days' => $days, 'firstHour' => $firstHour, 'lastHour' => $lastHour, 'today' => $today, 'date' => $date]) ?>
