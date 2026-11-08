<?php
/** @var array<string,array<string,mixed>> $days @var string $today */
$names = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
$any = false;
?>
<div class="kb-cal-list">
    <?php foreach ($days as $key => $day): $all = [...$day['allday'], ...$day['timed']]; if ($all === [] && empty($day['presence'])) { continue; } $any = true; ?>
        <section class="kb-cal-list-day">
            <h2 class="kb-cal-list-date"><?= $key === $today ? 'Aujourd\'hui · ' : '' ?><?= e($names[(int) $day['date']->format('w')] . ' ' . $day['date']->format('d/m')) ?></h2>
            <?php if (!empty($day['presence'])): ?><p class="kb-cal-presence"><?= e($day['presence']) ?></p><?php endif; ?>
            <?php foreach ($all as $e): ?>
                <?= $this->partial('calendar/_event_link', ['e' => $e, 'class' => 'kb-cal-row']) ?>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <?php if (!$any): ?><p class="kb-chat-empty">Rien de prévu sur cette période.</p><?php endif; ?>
</div>
