<?php
/** Pastille d'évènement. @var array<string,mixed> $e @var string $class */
$cancelled = $e['status'] === 'cancelled';
$time = (int) $e['all_day'] === 1 ? '' : (isset($e['local_start']) ? $e['local_start']->format('H:i') . ' ' : '');
?>
<a class="kb-cal-ev <?= e($class) ?><?= $cancelled ? ' is-cancelled' : '' ?><?= $e['response'] === 'pending' ? ' is-pending' : '' ?><?= $e['response'] === 'no' ? ' is-declined' : '' ?>" href="<?= e(url('/calendar/events/' . (int) $e['id'])) ?>">
    <?php if ($time !== ''): ?><span class="kb-cal-ev-time"><?= e($time) ?></span><?php endif; ?>
    <span class="kb-cal-ev-title"><?= e($e['title']) ?></span>
    <?php if ($cancelled): ?><span class="visually-hidden"> (annulé)</span><?php elseif ($e['response'] === 'pending'): ?><span class="visually-hidden"> (réponse attendue)</span><?php endif; ?>
</a>
