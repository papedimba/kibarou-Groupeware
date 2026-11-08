<?php
/** @var App\Core\Paginator $items @var int $unreadCount */
$this->layout('layouts/app', ['title' => 'Mes notifications', 'breadcrumbs' => [['Mes notifications', null]]]);
?>
<div class="kb-page-title">
    <h1>Mes notifications</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('/profile/notifications')) ?>"><i data-lucide="settings-2" aria-hidden="true"></i> Préférences</a>
        <?php if ($unreadCount > 0): ?>
            <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline-primary" type="submit"><i data-lucide="check-check" aria-hidden="true"></i> Tout marquer comme lu</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php if ($items->items === []): ?>
    <p class="text-secondary">Aucune notification pour l'instant.</p>
<?php else: ?>
    <ul class="kb-notifications list-unstyled">
        <?php foreach ($items->items as $n): ?>
            <?php $unread = $n['read_at'] === null; ?>
            <li class="kb-notification<?= $unread ? ' is-unread' : '' ?>">
                <form method="post" action="<?= e(url('/notifications/' . (int) $n['id'] . '/open')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="kb-notification-button">
                        <?php if ($unread): ?><span class="kb-unread-dot" aria-hidden="true"></span><span class="visually-hidden">Non lue : </span><?php endif; ?>
                        <span class="kb-notification-text">
                            <span class="kb-notification-title"><?= e($n['title']) ?></span>
                            <?php if ($n['body']): ?><span class="kb-notification-body"><?= e($n['body']) ?></span><?php endif; ?>
                        </span>
                        <time class="kb-notification-time" datetime="<?= e(str_replace(' ', 'T', (string) $n['created_at']) . 'Z') ?>"><?= e(relative_time((string) $n['created_at'])) ?></time>
                    </button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
    <?= $this->partial('partials/pagination', ['pager' => $items, 'query' => []]) ?>
<?php endif; ?>
