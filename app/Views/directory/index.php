<?php
/** @var list<array<string,mixed>> $members @var array<int,array<string,mixed>> $statuses @var array<int,true> $online @var string $q @var int $departmentId @var list<array<string,mixed>> $departments */
$this->layout('layouts/app', ['title' => 'Annuaire', 'breadcrumbs' => [['Annuaire', null]]]);
?>
<div class="kb-page-title">
    <h1>Annuaire</h1>
    <a class="btn btn-outline-primary" href="<?= e(url('/directory/org')) ?>"><i data-lucide="network" aria-hidden="true"></i> Organigramme</a>
</div>
<form class="row g-2 mb-3" method="get" role="search">
    <div class="col-12 col-md-6 col-lg-5">
        <label class="visually-hidden" for="q">Rechercher un collaborateur</label>
        <input class="form-control" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Nom, fonction, service ou chantier" autocomplete="off" data-directory-filter="kb-directory">
    </div>
    <div class="col-8 col-md-4 col-lg-3">
        <label class="visually-hidden" for="department_id">Service</label>
        <select class="form-select" id="department_id" name="department_id" data-autosubmit>
            <option value="">Tous les services</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>"<?= $departmentId === (int) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-4 col-md-2"><button class="btn btn-outline-secondary w-100" type="submit">Rechercher</button></div>
</form>

<ul class="kb-directory list-unstyled" id="kb-directory">
    <?php foreach ($members as $m): ?>
        <?php
        $name = $m['first_name'] . ' ' . $m['last_name'];
        $st = $statuses[(int) $m['id']] ?? null;
        $search = mb_strtolower(implode(' ', [$name, $m['last_name'] . ' ' . $m['first_name'], $m['position_title'], $m['department_name'], $st['site_label'] ?? '']));
        ?>
        <li class="kb-person" data-search="<?= e($search) ?>">
            <span class="kb-avatar" aria-hidden="true"><?= e(initials((string) $m['first_name'], (string) $m['last_name'])) ?></span>
            <div class="kb-person-main">
                <a class="kb-person-name" href="<?= e(url('/directory/' . (int) $m['id'])) ?>"><?= e($name) ?></a>
                <span class="kb-person-meta"><?= e(implode(' · ', array_filter([$m['position_title'], $m['department_name']])) ?: 'Fonction non renseignée') ?></span>
                <?= $this->partial('partials/presence_badge', ['status' => $st, 'online' => isset($online[(int) $m['id']])]) ?>
            </div>
            <div class="kb-person-actions">
                <?php if (!empty($m['phone'])): ?>
                    <a class="btn btn-outline-primary" href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $m['phone'])) ?>" aria-label="Appeler <?= e($name) ?> au <?= e($m['phone']) ?>"><i data-lucide="phone" aria-hidden="true"></i> <span class="kb-hide-sm"><?= e($m['phone']) ?></span><span class="kb-show-sm">Appeler</span></a>
                <?php endif; ?>
                <a class="btn btn-outline-primary" href="mailto:<?= e($m['email']) ?>" aria-label="Écrire un email à <?= e($name) ?>"><i data-lucide="mail" aria-hidden="true"></i> <span>Email</span></a>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
<p class="text-secondary" id="kb-directory-empty"<?= $members === [] ? '' : ' hidden' ?> role="status">Aucun collaborateur ne correspond.</p>
