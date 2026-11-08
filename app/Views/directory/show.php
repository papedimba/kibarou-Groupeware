<?php
/** @var array<string,mixed> $person @var bool $showPersonal @var bool $chatEnabled @var array<string,mixed>|null $status @var bool $online @var bool $canEditStatus @var array<string,string> $errors @var array<string,mixed> $old */
$name = $person['first_name'] . ' ' . $person['last_name'];
$this->layout('layouts/app', ['title' => $name, 'breadcrumbs' => [['Annuaire', '/directory'], [$name, null]]]);
$tel = static fn (string $n): string => preg_replace('/[^0-9+]/', '', $n) ?? '';
?>
<div class="kb-page-title">
    <div class="d-flex align-items-center gap-3">
        <?= avatar($person, 'kb-avatar-lg') ?>
        <div>
            <h1><?= e($name) ?></h1>
            <div class="text-secondary"><?= e(implode(' · ', array_filter([$person['position_title'], $person['department_name']])) ?: 'Fonction non renseignée') ?></div>
        </div>
    </div>
    <?php if ($chatEnabled && can('chat.use') && (int) $person['id'] !== (int) $currentUser['id']): ?>
        <a class="btn btn-primary" href="<?= e(url('/chat/with/' . (int) $person['id'])) ?>"><i data-lucide="message-square" aria-hidden="true"></i> Écrire</a>
    <?php endif; ?>
</div>
<div class="row g-4">
    <div class="col-lg-6">
        <section class="card" aria-labelledby="contact-title">
            <h2 class="card-header h6 m-0" id="contact-title">Coordonnées</h2>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Email</dt>
                    <dd class="col-sm-7"><a href="mailto:<?= e($person['email']) ?>"><?= e($person['email']) ?></a></dd>
                    <dt class="col-sm-5">Téléphone professionnel</dt>
                    <dd class="col-sm-7"><?php if (!empty($person['phone'])): ?><a href="tel:<?= e($tel((string) $person['phone'])) ?>"><?= e($person['phone']) ?></a><?php else: ?><span class="text-secondary">Non renseigné</span><?php endif; ?></dd>
                    <?php if ($showPersonal): ?>
                        <dt class="col-sm-5">Téléphone personnel</dt>
                        <dd class="col-sm-7"><?php if (!empty($person['phone_personal'])): ?><a href="tel:<?= e($tel((string) $person['phone_personal'])) ?>"><?= e($person['phone_personal']) ?></a><?php else: ?><span class="text-secondary">Non renseigné</span><?php endif; ?></dd>
                    <?php endif; ?>
                    <dt class="col-sm-5">Service</dt>
                    <dd class="col-sm-7"><?php if ($person['department_id']): ?><a href="<?= e(url('/directory/org#service-' . (int) $person['department_id'])) ?>"><?= e($person['department_name']) ?></a><?php else: ?><span class="text-secondary">Non renseigné</span><?php endif; ?></dd>
                    <dt class="col-sm-5">Responsable</dt>
                    <dd class="col-sm-7"><?php if (!empty($person['manager_name'])): ?><a href="<?= e(url('/directory/' . (int) $person['manager_id'])) ?>"><?= e($person['manager_name']) ?></a><?php else: ?><span class="text-secondary">Non renseigné</span><?php endif; ?></dd>
                </dl>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="card" id="presence" aria-labelledby="presence-title">
            <h2 class="card-header h6 m-0" id="presence-title">Aujourd'hui</h2>
            <div class="card-body">
                <p class="mb-3"><?= $this->partial('partials/presence_badge', ['status' => $status, 'online' => $online, 'full' => true]) ?></p>
                <?php if ($canEditStatus): ?>
                    <?= $this->partial('partials/presence_form', ['targetId' => (int) $person['id'], 'status' => $status, 'returnTo' => '/directory/' . (int) $person['id'], 'errors' => $errors, 'old' => $old, 'idPrefix' => 'fiche']) ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
