<?php
/** Résultats de recherche. @var string $q @var list<array<string,mixed>> $results @var string|null $error */
$this->layout('layouts/app', ['title' => 'Recherche', 'breadcrumbs' => [['Messagerie', '/chat'], ['Recherche', null]]]);
?>
<div class="kb-page-title"><h1>Rechercher dans mes messages</h1></div>
<form class="kb-narrow mb-3 d-flex gap-2" method="get" action="<?= e(url('/chat/search')) ?>" role="search">
    <label class="visually-hidden" for="q">Rechercher dans mes messages</label>
    <input class="form-control" type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="100" required>
    <button class="btn btn-primary" type="submit">Rechercher</button>
</form>
<?php if ($error): ?>
    <p class="text-danger" role="alert"><?= e($error) ?></p>
<?php elseif ($results === []): ?>
    <p class="kb-chat-empty">Aucun message ne correspond à « <?= e($q) ?> ».</p>
<?php else: ?>
    <p class="text-secondary"><?= count($results) ?> message<?= count($results) > 1 ? 's' : '' ?><?= count($results) >= 50 ? ' (les 50 plus récents)' : '' ?></p>
    <ul class="kb-search-results">
        <?php foreach ($results as $r): $mid = (int) $r['message_id']; ?>
            <li>
                <a class="kb-search-result" href="<?= e(url('/chat/' . (int) $r['conversation_id'] . '?around=' . $mid . '#m' . $mid)) ?>">
                    <span class="kb-search-head"><strong><?= e($r['conversation_title']) ?></strong><?= $r['sender_name'] !== $r['conversation_title'] ? ' · ' . e($r['sender_name']) : '' ?> · <?= e(format_date($r['created_at'])) ?></span>
                    <span class="kb-search-excerpt"><?= $r['excerpt_html'] /* échappé puis surligné par ChatRepository::highlight */ ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
