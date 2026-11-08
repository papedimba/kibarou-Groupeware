<?php
/** Un évènement de conversation. @var array<string,mixed> $ev @var int $me @var bool $isGroup */
$id = (int) $ev['id'];
if ($ev['type'] !== 'message'):
    $byMe = (int) $ev['actor_id'] === $me;
    $actor = $byMe ? 'Vous avez' : $ev['actor_name'] . ' a';
    $text = match ($ev['type']) {
        'member_added' => (int) $ev['target_user_id'] === $me ? $ev['actor_name'] . ' vous a ajouté' : $actor . ' ajouté ' . $ev['target_name'],
        'member_left' => $actor . ' quitté le groupe',
        'renamed' => $actor . ' renommé le groupe en « ' . $ev['detail'] . ' »',
        default => '',
    };
?>
    <li class="kb-msg-system" data-event-id="<?= $id ?>"><?= e($text) ?> · <?= e(format_date((string) $ev['created_at'], 'H:i')) ?></li>
<?php else:
    $mine = (int) $ev['sender_id'] === $me;
    $deleted = !empty($ev['deleted']);
    $sender = (string) $ev['actor_name'];
    $mid = (int) $ev['message_id'];
    $photos = array_values(array_filter($ev['attachments'] ?? [], static fn ($a) => $a['kind'] === 'image'));
    $files = array_values(array_filter($ev['attachments'] ?? [], static fn ($a) => $a['kind'] === 'file'));
    $reply = $ev['reply_to'] ?? null;
?>
    <li class="kb-msg<?= $mine ? ' is-mine' : '' ?>" id="m<?= $mid ?>" data-event-id="<?= $id ?>" data-message-id="<?= $mid ?>" data-mine="<?= $mine ? '1' : '0' ?>"
        data-created="<?= e(str_replace(' ', 'T', (string) $ev['created_at'])) ?>Z" data-deleted="<?= $deleted ? '1' : '0' ?>">
        <?php if ($isGroup && !$mine): ?><span class="kb-msg-author"><?= e($sender) ?></span><?php endif; ?>
        <div class="kb-msg-bubble">
            <?php if (!$mine): ?><span class="visually-hidden"><?= e($sender) ?> : </span><?php endif; ?>
            <?php if ($deleted): ?>
                <em class="kb-msg-deleted">Message supprimé</em>
            <?php else: ?>
                <?php if ($reply !== null): ?>
                    <a class="kb-msg-reply" href="#m<?= (int) $reply['id'] ?>" data-reply-to="<?= (int) $reply['id'] ?>">
                        <?php if (!empty($reply['hidden'])): ?>Message antérieur à votre arrivée
                        <?php elseif (!empty($reply['deleted'])): ?>Message supprimé
                        <?php else: ?><strong><?= e($reply['sender_name']) ?></strong> <?= e($reply['excerpt']) ?><?php endif; ?>
                    </a>
                <?php endif; ?>
                <?php if ($photos !== []): ?>
                    <div class="kb-msg-photos kb-msg-photos-<?= min(count($photos), 4) ?>">
                        <?php foreach (array_slice($photos, 0, 4) as $i => $p): ?>
                            <a class="kb-msg-photo" href="<?= e(url('/chat/attachments/' . (int) $p['id'])) ?>" data-photo<?= $i > 0 && $i === 3 && count($photos) > 4 ? ' data-more="+' . (count($photos) - 4) . '"' : '' ?>>
                                <img src="<?= e(url('/chat/attachments/' . (int) $p['id'] . '/thumb')) ?>" alt="Photo envoyée par <?= e($sender) ?>" loading="lazy"
                                     width="<?= (int) $p['width'] ?>" height="<?= (int) $p['height'] ?>">
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach (array_slice($photos, 4) as $p): ?>
                        <a class="kb-msg-photo-extra" href="<?= e(url('/chat/attachments/' . (int) $p['id'])) ?>" data-photo hidden>Photo</a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach ($files as $f): $isSheet = str_ends_with(strtolower((string) $f['name']), '.xlsx'); ?>
                    <a class="kb-msg-file" href="<?= e(url('/chat/attachments/' . (int) $f['id'])) ?>" download>
                        <i data-lucide="<?= $isSheet ? 'file-spreadsheet' : 'file-text' ?>" aria-hidden="true"></i>
                        <span class="kb-msg-file-text"><span class="kb-msg-file-name"><?= e($f['name']) ?></span><span class="kb-msg-file-size"><?= e(format_bytes((int) $f['size'])) ?> · Télécharger</span></span>
                    </a>
                <?php endforeach; ?>
                <?php if ((string) $ev['body'] !== ''): ?><div class="kb-msg-text"><?= linkify((string) $ev['body']) ?></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <span class="kb-msg-meta">
            <time class="kb-msg-time" datetime="<?= e(str_replace(' ', 'T', (string) $ev['created_at'])) ?>Z"><?= e(format_date((string) $ev['created_at'], 'H:i')) ?></time>
            <?php if (!empty($ev['edited_at']) && !$deleted): ?><span class="kb-msg-edited">modifié</span><?php endif; ?>
            <button type="button" class="kb-msg-actions-btn" data-msg-actions aria-label="Actions sur ce message" hidden><i data-lucide="ellipsis" aria-hidden="true"></i></button>
        </span>
        <?php if (!empty($ev['reactions'])): ?>
            <div class="kb-msg-reactions">
                <?php foreach ($ev['reactions'] as $r): ?>
                    <button type="button" class="kb-reaction" data-react="<?= e($r['emoji']) ?>" aria-pressed="<?= $r['mine'] ? 'true' : 'false' ?>" title="<?= e(implode(', ', $r['names'])) ?>">
                        <span aria-hidden="true"><?= e($r['emoji']) ?> <?= (int) $r['count'] ?></span>
                        <span class="visually-hidden"><?= e($r['emoji']) ?> : <?= e(implode(', ', $r['names'])) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </li>
<?php endif; ?>
