<?php /** @var array<string,mixed> $p @var array<int,array<string,mixed>> $statuses @var bool $head */ ?>
<li class="kb-org-person<?= !empty($head) ? ' kb-org-head' : '' ?>">
    <?= avatar($p) ?>
    <span class="kb-person-main">
        <a class="kb-person-name" href="<?= e(url('/directory/' . (int) $p['id'])) ?>"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></a>
        <span class="kb-person-meta"><?= !empty($head) ? 'Responsable' . ($p['position_title'] ? ' · ' . e($p['position_title']) : '') : e($p['position_title'] ?: 'Fonction non renseignée') ?></span>
        <?= $this->partial('partials/presence_badge', ['status' => $statuses[(int) $p['id']] ?? null, 'online' => false]) ?>
    </span>
</li>
