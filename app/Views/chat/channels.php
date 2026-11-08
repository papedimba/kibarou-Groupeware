<?php
/** Salons de l'entreprise. @var list<array<string,mixed>> $channels @var bool $archived @var bool $canCreate */
$this->layout('layouts/app', ['title' => 'Salons', 'breadcrumbs' => [['Messagerie', '/chat'], ['Salons', null]]]);
?>
<div class="kb-page-title">
    <h1>Salons</h1>
    <?php if ($canCreate): ?>
        <a class="btn btn-primary" href="<?= e(url('/chat/channels/new')) ?>"><i data-lucide="plus" aria-hidden="true"></i> Nouveau salon</a>
    <?php endif; ?>
</div>
<p class="text-secondary kb-narrow">Un salon est ouvert à toute l'entreprise : en le rejoignant, vous lisez tout ce qui s'y est dit. Les alertes y sont coupées par défaut.</p>
<nav class="kb-tabs" aria-label="Salons">
    <a href="<?= e(url('/chat/channels')) ?>"<?= $archived ? '' : ' aria-current="page"' ?>>Ouverts</a>
    <a href="<?= e(url('/chat/channels?archived=1')) ?>"<?= $archived ? ' aria-current="page"' : '' ?>>Archivés</a>
</nav>
<?php if ($channels === []): ?>
    <p class="kb-chat-empty"><?= $archived ? 'Aucun salon archivé.' : 'Aucun salon pour l\'instant.' ?></p>
<?php else: ?>
    <ul class="kb-channels">
        <?php foreach ($channels as $c): $id = (int) $c['id']; ?>
            <li class="kb-channel">
                <span class="kb-avatar kb-avatar-group" aria-hidden="true"><i data-lucide="<?= $archived ? 'archive' : 'hash' ?>"></i></span>
                <div class="kb-channel-text">
                    <span class="kb-channel-name"><?= e($c['name']) ?></span>
                    <span class="kb-channel-meta"><?= (int) $c['members'] ?> membre<?= $c['members'] > 1 ? 's' : '' ?> · actif <?= e(relative_time((string) $c['last_event_at'])) ?><?= !empty($c['description']) ? ' · ' . e($c['description']) : '' ?></span>
                </div>
                <div class="kb-channel-actions">
                    <?php if ($c['member']): ?>
                        <a class="btn btn-outline-primary" href="<?= e(url("/chat/$id")) ?>">Ouvrir</a>
                    <?php else: ?>
                        <form method="post" action="<?= e(url("/chat/channels/$id/join")) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Rejoindre<span class="visually-hidden"> <?= e($c['name']) ?></span></button></form>
                    <?php endif; ?>
                    <?php if ($c['can_manage']): ?>
                        <a class="btn btn-light" href="<?= e(url("/chat/channels/$id/edit")) ?>">Modifier<span class="visually-hidden"> <?= e($c['name']) ?></span></a>
                        <?php if ($archived): ?>
                            <form method="post" action="<?= e(url("/chat/channels/$id/unarchive")) ?>"><?= csrf_field() ?><button class="btn btn-light" type="submit">Rouvrir</button></form>
                        <?php else: ?>
                            <form method="post" action="<?= e(url("/chat/channels/$id/archive")) ?>" data-confirm="Archiver le salon « <?= e($c['name']) ?> » ? Il restera lisible, mais n'acceptera plus de messages." data-confirm-action="Archiver"><?= csrf_field() ?><button class="btn btn-light" type="submit">Archiver</button></form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
