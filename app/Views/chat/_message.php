<?php
/** Un évènement de conversation. @var array<string,mixed> $ev @var int $me @var bool $isGroup */
$id = (int) $ev['id'];
if ($ev['type'] !== 'message'):
    $byMe = (int) $ev['actor_id'] === $me;
    $actor = $byMe ? 'Vous avez' : $ev['actor_name'] . ' a';
    $text = match ($ev['type']) {
        'member_added' => (int) $ev['target_user_id'] === $me ? $ev['actor_name'] . ' vous a ajouté' : $actor . ' ajouté ' . $ev['target_name'],
        'member_left' => $actor . ' quitté le groupe',
        'renamed' => $actor . ' renommé le groupe',
        default => '',
    };
?>
    <li class="kb-msg-system" data-event-id="<?= $id ?>"><?= e($text) ?> · <?= e(format_date((string) $ev['created_at'], 'H:i')) ?></li>
<?php else: $mine = (int) $ev['sender_id'] === $me; ?>
    <li class="kb-msg<?= $mine ? ' is-mine' : '' ?>" data-event-id="<?= $id ?>">
        <?php if ($isGroup && !$mine): ?><span class="kb-msg-author"><?= e($ev['actor_name']) ?></span><?php endif; ?>
        <div class="kb-msg-bubble">
            <?php if (!$mine): ?><span class="visually-hidden"><?= e($ev['actor_name']) ?> : </span><?php endif; ?>
            <?= linkify((string) $ev['body']) ?>
        </div>
        <time class="kb-msg-time" datetime="<?= e(str_replace(' ', 'T', (string) $ev['created_at'])) ?>Z"><?= e(format_date((string) $ev['created_at'], 'H:i')) ?></time>
    </li>
<?php endif; ?>
