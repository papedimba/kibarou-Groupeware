<?php
/**
 * Messagerie : liste des conversations et, si choisie, la conversation.
 * Téléphone : la liste seule, ou la conversation en plein écran avec un bouton retour.
 * @var list<array<string,mixed>> $conversations @var array<string,mixed>|null $conversation
 */
$title = $conversation !== null ? (string) $conversation['title'] : 'Messagerie';
$this->layout('layouts/app', ['title' => $title, 'breadcrumbs' => $conversation !== null ? [['Messagerie', '/chat'], [$title, null]] : [['Messagerie', null]]]);
$me = (int) $currentUser['id'];
?>
<div class="kb-chat<?= $conversation !== null ? ' has-conversation' : '' ?>" data-chat data-since="<?= (int) $since ?>"
     data-poll-active="<?= (int) $poll['active_ms'] ?>" data-poll-idle="<?= (int) $poll['idle_ms'] ?>" data-poll-background="<?= (int) $poll['background_ms'] ?>">
    <section class="kb-chat-sidebar" aria-label="Conversations">
        <?= $this->partial('chat/_list', ['conversations' => $conversations, 'conversation' => $conversation]) ?>
    </section>

    <?php if ($conversation === null): ?>
        <div class="kb-chat-placeholder" aria-hidden="true">
            <i data-lucide="messages-square"></i>
            <p>Choisissez une conversation pour l'afficher.</p>
        </div>
    <?php else:
        $type = (string) $conversation['type'];
        // Conversation à plusieurs (auteur affiché) ; seuls les groupes ont un panneau de membres.
        $isGroup = $type !== 'direct';
        $isSalon = $type === 'channel' || $type === 'department';
        $archived = !empty($conversation['archived_at']);
        $cid = (int) $conversation['id'];
        $memberIds = array_map(static fn ($m) => (int) $m['id'], $members);
        $addable = array_values(array_filter($people, static fn ($p) => !in_array((int) $p['id'], $memberIds, true)));
    ?>
        <section class="kb-chat-conversation" aria-labelledby="chat-title"
                 data-chat-conversation="<?= $cid ?>" data-type="<?= e($type) ?>" data-me="<?= $me ?>" data-group="<?= $isGroup ? '1' : '0' ?>" data-has-more="<?= count($events) >= 50 ? '1' : '0' ?>" data-last-event="<?= (int) $lastEventId ?>">
            <header class="kb-chat-head">
                <a class="btn btn-light kb-chat-back" href="<?= e(url('/chat')) ?>" aria-label="Retour aux conversations"><i data-lucide="arrow-left" aria-hidden="true"></i></a>
                <?php if ($isGroup): ?>
                    <span class="kb-avatar kb-avatar-group" aria-hidden="true"><i data-lucide="<?= $type === 'channel' ? 'hash' : ($type === 'department' ? 'building-2' : 'users') ?>"></i></span>
                <?php else: ?>
                    <?= avatar(['id' => $conversation['other_id'], 'first_name' => $conversation['other_first_name'], 'last_name' => $conversation['other_last_name'], 'avatar_path' => $conversation['other_avatar_path']]) ?>
                <?php endif; ?>
                <div class="kb-chat-head-text">
                    <h2 class="kb-chat-title" id="chat-title"><?= e($conversation['title']) ?></h2>
                    <?php if ($isGroup): ?>
                        <span class="kb-chat-sub"><?= $type === 'department' ? 'Salon du service · ' : '' ?><?= count($members) ?> membre<?= count($members) > 1 ? 's' : '' ?><?= !empty($conversation['description']) ? ' · ' . e($conversation['description']) : '' ?></span>
                    <?php elseif (can('directory.view')): ?>
                        <a class="kb-chat-sub" href="<?= e(url('/directory/' . (int) $conversation['other_id'])) ?>">Voir la fiche</a>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?= e(url("/chat/$cid/notify")) ?>" class="kb-chat-notify">
                    <?= csrf_field() ?>
                    <input type="hidden" name="on" value="<?= (int) $conversation['notify'] === 1 ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-light" aria-pressed="<?= (int) $conversation['notify'] === 1 ? 'true' : 'false' ?>"
                            title="<?= (int) $conversation['notify'] === 1 ? 'Alertes activées : cliquer pour les couper' : 'Alertes coupées : cliquer pour les activer' ?>">
                        <i data-lucide="<?= (int) $conversation['notify'] === 1 ? 'bell' : 'bell-off' ?>" aria-hidden="true"></i><span class="kb-chat-notify-label">Alertes</span>
                    </button>
                </form>
            </header>

            <?php if ($isSalon): ?>
                <div class="kb-chat-salon-actions">
                    <?php if ($type === 'channel'): ?>
                        <form method="post" action="<?= e(url("/chat/channels/$cid/leave")) ?>" data-confirm="Quitter le salon « <?= e($conversation['name']) ?> » ? Vous pourrez le rejoindre à nouveau depuis la liste des salons." data-confirm-action="Quitter">
                            <?= csrf_field() ?>
                            <button class="btn btn-link btn-sm" type="submit">Quitter le salon</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                        <a class="btn btn-link btn-sm" href="<?= e(url("/chat/channels/$cid/edit")) ?>">Modifier le salon</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($archived): ?>
                <div class="kb-chat-archived" role="status"><i data-lucide="archive" aria-hidden="true"></i> Salon archivé : lecture seule.</div>
            <?php endif; ?>

            <?php if ($type === 'group'): ?>
                <details class="kb-chat-group">
                    <summary>Membres et réglages du groupe</summary>
                    <div class="kb-chat-group-body">
                        <ul class="kb-chat-members">
                            <?php foreach ($members as $m): ?>
                                <li><?= avatar($m) ?> <?= e($m['first_name'] . ' ' . $m['last_name']) ?><?= (int) $m['id'] === $me ? ' <span class="text-secondary">(vous)</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($addable !== []): ?>
                            <form method="post" action="<?= e(url("/chat/$cid/members")) ?>" class="kb-chat-inline-form">
                                <?= csrf_field() ?>
                                <fieldset class="w-100">
                                    <legend class="form-label fs-6">Ajouter des membres</legend>
                                    <div class="kb-chat-people kb-chat-people-sm">
                                        <?php foreach ($addable as $p): ?>
                                            <label class="kb-check-row" for="add<?= (int) $p['id'] ?>">
                                                <input class="form-check-input" type="checkbox" name="members[]" value="<?= (int) $p['id'] ?>" id="add<?= (int) $p['id'] ?>">
                                                <span><?= e($p['name']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                                <div class="form-text">Les nouveaux membres ne voient pas les messages échangés avant leur arrivée.</div>
                                <button class="btn btn-outline-primary" type="submit">Ajouter</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url("/chat/$cid/rename")) ?>" class="kb-chat-inline-form">
                            <?= csrf_field() ?>
                            <label class="form-label" for="group-name">Nom du groupe</label>
                            <div class="d-flex gap-2">
                                <input class="form-control" id="group-name" name="name" value="<?= e($conversation['name']) ?>" maxlength="80" required>
                                <button class="btn btn-outline-primary" type="submit">Renommer</button>
                            </div>
                        </form>
                        <form method="post" action="<?= e(url("/chat/$cid/leave")) ?>" data-confirm="Quitter le groupe « <?= e($conversation['name']) ?> » ? Vous ne recevrez plus ses messages." data-confirm-action="Quitter" data-confirm-danger>
                            <?= csrf_field() ?>
                            <button class="btn btn-outline-danger" type="submit">Quitter le groupe</button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>

            <ol class="kb-chat-messages" data-chat-messages aria-live="polite" aria-label="Messages">
                <?php if ($events === []): ?>
                    <li class="kb-msg-system" data-chat-empty>Aucun message. Écrivez le premier.</li>
                <?php endif; ?>
                <?php $day = null; foreach ($events as $ev): ?>
                    <?php $d = day_label((string) $ev['created_at']); if ($d !== $day): $day = $d; ?>
                        <li class="kb-msg-day" data-day="<?= e($d) ?>"><span><?= e($d) ?></span></li>
                    <?php endif; ?>
                    <?= $this->partial('chat/_message', ['ev' => $ev, 'me' => $me, 'isGroup' => $isGroup, 'convType' => $type]) ?>
                <?php endforeach; ?>
            </ol>

            <?php if ($archived): ?>
                <p class="kb-chat-compose kb-chat-compose-closed">Ce salon est archivé : il n'accepte plus de messages.</p>
            <?php else: ?>
            <form class="kb-chat-compose" method="post" action="<?= e(url("/chat/$cid")) ?>" data-chat-form>
                <?= csrf_field() ?>
                <div class="kb-chat-banner" data-chat-banner hidden>
                    <span class="kb-chat-banner-text" data-chat-banner-text></span>
                    <button type="button" class="btn btn-link btn-sm" data-chat-banner-cancel>Annuler</button>
                </div>
                <ul class="kb-chat-tray" data-chat-tray aria-label="Pièces jointes à envoyer" hidden></ul>
                <label class="btn btn-light kb-chat-attach" data-chat-attach hidden>
                    <i data-lucide="paperclip" aria-hidden="true"></i><span class="visually-hidden">Joindre des photos ou des fichiers</span>
                    <input type="file" class="visually-hidden" data-chat-file multiple accept="image/*,.pdf,.docx,.xlsx">
                </label>
                <label class="visually-hidden" for="chat-body">Votre message</label>
                <textarea class="form-control<?= invalid($errors, 'body') ?>" id="chat-body" name="body" rows="1" maxlength="4000" placeholder="Votre message" required><?= e($old['body'] ?? '') ?></textarea>
                <button class="btn btn-primary kb-chat-send" type="submit"><i data-lucide="send" aria-hidden="true"></i><span class="visually-hidden">Envoyer</span></button>
                <?= field_error($errors, 'body') ?>
            </form>
            <?php endif; ?>
            <form hidden data-chat-delete action="#" data-confirm="Supprimer ce message ? Il sera remplacé par « Message supprimé » pour tout le monde, et ses fichiers seront effacés." data-confirm-action="Supprimer" data-confirm-danger>
                <button type="submit">Supprimer</button>
            </form>
        </section>
    <?php endif; ?>
</div>
