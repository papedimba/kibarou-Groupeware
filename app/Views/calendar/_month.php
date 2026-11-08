<?php
/** @var array<string,array<string,mixed>> $days @var string $today @var DateTimeImmutable $date */
$names = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
?>
<div class="kb-cal-month">
    <?php foreach ($names as $n): ?><div class="kb-cal-month-head" aria-hidden="true"><?= e($n) ?></div><?php endforeach; ?>
    <?php foreach ($days as $key => $day): $all = [...$day['allday'], ...$day['timed']]; ?>
        <div class="kb-cal-month-day<?= $key === $today ? ' is-today' : '' ?><?= $day['date']->format('m') !== $date->format('m') ? ' is-other' : '' ?>">
            <a class="kb-cal-month-num" href="<?= e(url('/calendar?view=week&date=' . $key)) ?>" aria-label="Semaine du <?= e($day['date']->format('d/m')) ?>"><?= e($day['date']->format('j')) ?></a>
            <?php foreach (array_slice($all, 0, 3) as $e): ?>
                <?= $this->partial('calendar/_event_link', ['e' => $e, 'class' => 'kb-cal-chip']) ?>
            <?php endforeach; ?>
            <?php if (count($all) > 3): ?><a class="kb-cal-more" href="<?= e(url('/calendar?view=list&date=' . $key)) ?>">+<?= count($all) - 3 ?> autre<?= count($all) > 4 ? 's' : '' ?></a><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
