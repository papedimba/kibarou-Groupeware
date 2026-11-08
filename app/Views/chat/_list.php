<?php
/** @var list<array<string,mixed>> $conversations @var array<string,mixed>|null $conversation */
$currentId = $conversation !== null ? (int) $conversation['id'] : 0;
?>
<div class="kb-chat-list-head">
    <h1 class="h5 m-0 fw-bold">Messagerie</h1>
    <div class="kb-chat-list-actions">
        <a class="btn btn-light btn-sm kb-chat-new" href="<?= e(url('/chat/channels')) ?>"><i data-lucide="hash" aria-hidden="true"></i> Salons</a>
        <a class="btn btn-primary btn-sm kb-chat-new" href="<?= e(url('/chat/new')) ?>"><i data-lucide="square-pen" aria-hidden="true"></i> Nouvelle conversation</a>
    </div>
</div>
<form class="kb-chat-search" method="get" action="<?= e(url('/chat/search')) ?>" role="search">
    <label class="visually-hidden" for="chat-search">Rechercher dans mes messages</label>
    <input class="form-control" type="search" id="chat-search" name="q" placeholder="Rechercher dans mes messages" minlength="2" maxlength="100" value="<?= e($q ?? '') ?>">
</form>
<?php if ($conversations === []): ?>
    <p class="kb-chat-empty">Aucune conversation pour l'instant.<br>Écrivez à un collègue depuis l'annuaire ou créez un groupe.</p>
<?php endif; ?>
    <ul class="kb-chat-list" data-chat-list<?= $conversations === [] ? ' hidden' : '' ?>>
        <?php foreach ($conversations as $c): ?>
            <?php $unread = (int) $c['unread']; $active = (int) $c['id'] === $currentId; ?>
            <li>
                <a class="kb-chat-item<?= $unread > 0 ? ' is-unread' : '' ?><?= $active ? ' is-active' : '' ?>" href="<?= e(url('/chat/' . (int) $c['id'])) ?>"<?= $active ? ' aria-current="page"' : '' ?> data-conversation-id="<?= (int) $c['id'] ?>">
                    <?php if ($c['type'] !== 'direct'): ?>
                        <span class="kb-avatar kb-avatar-group" aria-hidden="true"><i data-lucide="<?= $c['type'] === 'channel' ? 'hash' : ($c['type'] === 'department' ? 'building-2' : 'users') ?>"></i></span>
                    <?php else: ?>
                        <?= avatar(['id' => $c['other_id'], 'first_name' => $c['other_first_name'], 'last_name' => $c['other_last_name'], 'avatar_path' => $c['other_avatar_path']]) ?>
                    <?php endif; ?>
                    <span class="kb-chat-item-text">
                        <span class="kb-chat-item-title"><?= e($c['title']) ?></span>
                        <span class="kb-chat-item-last"><?= $c['last_body'] !== null ? e($c['last_body']) : 'Pas encore de message' ?></span>
                    </span>
                    <span class="kb-chat-item-side">
                        <span class="kb-chat-item-time"><?= e(relative_time((string) $c['last_event_at'])) ?></span>
                        <?php if ($unread > 0): ?><span class="kb-chat-count"><?= $unread > 99 ? '99+' : $unread ?><span class="visually-hidden"> non lu<?= $unread > 1 ? 's' : '' ?></span></span><?php endif; ?>
                    </span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
