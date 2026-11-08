<?php
/**
 * Mise en page principale (utilisateur connecté).
 * @var string $content
 * @var array<string,mixed> $currentUser
 * @var string $currentPath
 * @var string|null $title
 * @var list<array{0:string,1:?string}> $breadcrumbs
 */
$title ??= 'Kibarou Groupware';
$breadcrumbs ??= [];
$nav = static function (string $path, string $icon, string $label) use ($currentPath): string {
    $active = $currentPath === $path || ($path !== '/' && $path !== '/admin' && str_starts_with($currentPath, $path));
    return '<a href="' . e(url($path)) . '" class="' . ($active ? 'active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>'
        . '<i data-lucide="' . e($icon) . '" aria-hidden="true"></i>' . e($label) . '</a>';
};
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · Kibarou Groupware</title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body data-last-id="<?= (int) ($lastNotificationId ?? 0) ?>">
<a class="skip-link" href="#contenu">Aller au contenu</a>
<div class="kb-shell">
    <aside class="kb-sidebar" id="kb-sidebar" aria-label="Navigation principale">
        <a class="kb-brand" href="<?= e(url('/')) ?>">
            <span class="kb-brand-mark" aria-hidden="true">K</span>
            <span>Kibarou<small>Groupware</small></span>
        </a>
        <nav class="kb-nav">
            <?= $nav('/', 'house', 'Accueil') ?>
            <?php if (can('directory.view')): ?><?= $nav('/directory', 'contact', 'Annuaire') ?><?php endif; ?>
            <?= $nav('/profile', 'user', 'Mon profil') ?>
            <?php if (can('admin.access')): ?>
                <div class="kb-nav-title">Administration</div>
                <?= $nav('/admin', 'shield', 'Vue d\'ensemble') ?>
                <?php if (can('users.view')): ?><?= $nav('/admin/users', 'users', 'Utilisateurs') ?><?php endif; ?>
                <?php if (can('organization.manage')): ?>
                    <?= $nav('/admin/departments', 'building-2', 'Services') ?>
                    <?= $nav('/admin/positions', 'briefcase', 'Fonctions') ?>
                <?php endif; ?>
                <?php if (can('roles.manage')): ?><?= $nav('/admin/roles', 'key-round', 'Rôles et permissions') ?><?php endif; ?>
                <?php if (can('audit.view')): ?><?= $nav('/admin/audit', 'scroll-text', 'Journal d\'audit') ?><?php endif; ?>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="kb-main">
        <header class="kb-header">
            <button type="button" class="btn btn-light kb-menu-btn" data-kb-toggle="sidebar" aria-controls="kb-sidebar" aria-expanded="false" aria-label="Ouvrir le menu">
                <i data-lucide="menu" aria-hidden="true"></i>
            </button>
            <p class="kb-header-title" aria-hidden="true"><?= e($title) ?></p>
            <nav aria-label="Fil d'Ariane">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Accueil</a></li>
                    <?php foreach ($breadcrumbs as [$label, $link]): ?>
                        <?php if ($link !== null): ?>
                            <li class="breadcrumb-item"><a href="<?= e(url($link)) ?>"><?= e($label) ?></a></li>
                        <?php else: ?>
                            <li class="breadcrumb-item active" aria-current="page"><?= e($label) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <div class="ms-auto d-flex align-items-center gap-2">
                <?php $unreadCount ??= 0; ?>
                <a class="btn btn-light kb-bell" href="<?= e(url('/notifications')) ?>" aria-label="Notifications, <?= (int) $unreadCount ?> non lue<?= $unreadCount > 1 ? 's' : '' ?>" data-bell>
                    <i data-lucide="bell" aria-hidden="true"></i>
                    <span class="kb-bell-badge" data-unread-badge<?= $unreadCount > 0 ? '' : ' hidden' ?>><?= $unreadCount > 99 ? '99+' : (int) $unreadCount ?></span>
                </a>
                <div class="dropdown">
                    <button class="btn btn-link text-decoration-none text-body d-flex align-items-center gap-2 p-1" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= avatar($currentUser) ?>
                        <span class="d-none d-md-inline fw-medium"><?= e($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?></span>
                        <i data-lucide="chevron-down" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i data-lucide="user" aria-hidden="true"></i> Mon profil</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('/profile/password')) ?>"><i data-lucide="lock" aria-hidden="true"></i> Mot de passe</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= e(url('/logout')) ?>">
                                <?= csrf_field() ?>
                                <button class="dropdown-item text-danger" type="submit"><i data-lucide="log-out" aria-hidden="true"></i> Se déconnecter</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="kb-content" id="contenu">
            <?php if (!empty($flash)): ?>
                <div class="alert alert-<?= e($flashType === 'danger' ? 'danger' : 'success') ?> d-flex align-items-center gap-2" role="alert">
                    <i data-lucide="<?= $flashType === 'danger' ? 'alert-triangle' : 'check-circle-2' ?>" aria-hidden="true"></i>
                    <span><?= e($flash) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($errors) && empty($hideErrorSummary)): ?>
                <div class="alert alert-danger" role="alert">Le formulaire contient des erreurs. Corrigez les champs signalés.</div>
            <?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<div class="modal fade" id="kb-confirm" tabindex="-1" aria-labelledby="kb-confirm-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="kb-confirm-title">Confirmation</h2></div>
            <div class="modal-body"><p class="mb-0" data-kb-confirm-text></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" data-kb-confirm-ok>Confirmer</button>
            </div>
        </div>
    </div>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
