<?php
/** @var array<string,mixed> $currentUser @var string|null $department @var list<array<string,mixed>> $modules */
$this->layout('layouts/app', ['title' => 'Tableau de bord']);
$icons = ['auth' => 'shield-check', 'chat' => 'message-square', 'calendar' => 'calendar', 'tasks' => 'check-square', 'projects' => 'folder-kanban',
    'meetings' => 'users-round', 'documents' => 'file-text', 'announcements' => 'megaphone', 'workflows' => 'git-pull-request',
    'directory' => 'contact', 'notifications' => 'bell'];
?>
<div class="kb-page-title">
    <div>
        <h1>Bonjour <?= e($currentUser['first_name']) ?></h1>
        <p class="text-secondary mb-0"><?= $department !== null ? e($department) . ' · ' : '' ?>Bienvenue sur l'intranet de Kibarou Construction.</p>
    </div>
</div>

<div class="card">
    <div class="card-header">Modules</div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($modules as $m): ?>
                <div class="col-12 col-sm-6 col-xl-4">
                    <div class="kb-module<?= (int) $m['is_enabled'] === 1 ? '' : ' off' ?>">
                        <i data-lucide="<?= e($icons[$m['slug']] ?? 'box') ?>" aria-hidden="true"></i>
                        <span class="fw-medium"><?= e($m['name']) ?></span>
                        <span class="ms-auto badge <?= (int) $m['is_enabled'] === 1 ? 'text-bg-success' : 'text-bg-light' ?>"><?= (int) $m['is_enabled'] === 1 ? 'Disponible' : 'En préparation' ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
