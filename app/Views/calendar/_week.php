<?php
/** @var array<string,array<string,mixed>> $days @var int $firstHour @var int $lastHour @var string $today */
$names = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
?>
<div class="kb-cal-week" role="grid" aria-label="Semaine">
    <div class="kb-cal-week-head" role="row">
        <span class="kb-cal-gutter" aria-hidden="true"></span>
        <?php $i = 0; foreach ($days as $key => $day): ?>
            <div class="kb-cal-dayhead<?= $key === $today ? ' is-today' : '' ?>" role="columnheader">
                <span><?= e($names[$i++]) ?></span> <strong><?= e($day['date']->format('d')) ?></strong>
                <?php foreach ($day['allday'] as $e): ?>
                    <?= $this->partial('calendar/_event_link', ['e' => $e, 'class' => 'kb-cal-allday']) ?>
                <?php endforeach; ?>
                <?php if (!empty($day['presence'])): ?><span class="kb-cal-presence"><?= e($day['presence']['label']) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="kb-cal-week-body" role="row">
        <div class="kb-cal-gutter" aria-hidden="true">
            <?php for ($h = $firstHour; $h < $lastHour; $h++): ?><span class="kb-cal-hour"><?= $h ?> h</span><?php endfor; ?>
        </div>
        <?php foreach ($days as $key => $day): ?>
            <div class="kb-cal-daycol<?= $key === $today ? ' is-today' : '' ?>" role="gridcell" aria-label="<?= e($day['date']->format('d/m')) ?>">
                <?php foreach ($day['timed'] as $e): ?>
                    <?= $this->partial('calendar/_event_link', ['e' => $e, 'class' => 'kb-ev-top-' . $e['top'] . ' kb-ev-h-' . $e['height'] . ' kb-ev-lane-' . $e['lane'] . '-' . $e['lanes']]) ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
