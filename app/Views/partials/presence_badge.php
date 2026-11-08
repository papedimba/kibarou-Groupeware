<?php
/** @var array<string,mixed>|null $status statut valide du jour @var bool $online @var bool $full afficher note et date */
$full ??= false;
?>
<span class="kb-presence">
    <?php if ($online): ?><span class="kb-online" title="En ligne" aria-hidden="true"></span><span class="visually-hidden">en ligne</span><?php endif; ?>
    <?php if ($status !== null): ?>
        <span class="kb-status kb-status-<?= e($status['status']) ?>"><?= e($status['label']) ?><?= $status['site_label'] ? ' : ' . e($status['site_label']) : '' ?></span>
        <?php if ($full && $status['until_date']): ?><span class="kb-status-extra">jusqu'au <?= e(format_date($status['until_date'] . ' 12:00:00', 'd/m/Y')) ?></span><?php endif; ?>
        <?php if ($full && $status['note']): ?><span class="kb-status-extra"><?= e($status['note']) ?></span><?php endif; ?>
    <?php else: ?>
        <span class="kb-status kb-status-none">Statut non renseigné</span>
    <?php endif; ?>
</span>
