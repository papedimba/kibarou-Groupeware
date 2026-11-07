<?php
/**
 * @var array<string,mixed>|null $target @var list<int> $userRoleIds @var list<array<string,mixed>> $roles
 * @var list<array<string,mixed>> $departments @var list<array<string,mixed>> $positions @var list<array<string,mixed>> $managers
 * @var bool $actorIsSuper @var array<string,string> $errors @var array<string,mixed> $old
 */
$isNew = $target === null;
$title = $isNew ? 'Nouvel utilisateur' : 'Modifier ' . $target['first_name'] . ' ' . $target['last_name'];
$this->layout('layouts/app', ['title' => $title, 'breadcrumbs' => [['Administration', '/admin'], ['Utilisateurs', '/admin/users'], [$isNew ? 'Nouveau' : 'Modifier', null]]]);
$val = static fn (string $k) => $old[$k] ?? ($target[$k] ?? '');
$selectedRoles = isset($old['roles']) ? array_map('intval', (array) $old['roles']) : $userRoleIds;
$select = static function (string $name, string $label, array $options, string $labelKey) use ($val, $errors): string {
    $html = '<label class="form-label" for="' . $name . '">' . e($label) . '</label><select class="form-select' . invalid($errors, $name) . '" id="' . $name . '" name="' . $name . '"><option value="">—</option>';
    foreach ($options as $o) {
        $html .= '<option value="' . (int) $o['id'] . '"' . ((string) $val($name) === (string) $o['id'] ? ' selected' : '') . '>' . e($o[$labelKey]) . '</option>';
    }
    return $html . '</select>' . field_error($errors, $name);
};
?>
<div class="kb-page-title"><h1><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($isNew ? '/admin/users' : '/admin/users/' . $target['id'])) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card"><div class="card-header">Identité et rattachement</div><div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="first_name">Prénom *</label>
                    <input class="form-control<?= invalid($errors, 'first_name') ?>" id="first_name" name="first_name" value="<?= e($val('first_name')) ?>" required maxlength="100">
                    <?= field_error($errors, 'first_name') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="last_name">Nom *</label>
                    <input class="form-control<?= invalid($errors, 'last_name') ?>" id="last_name" name="last_name" value="<?= e($val('last_name')) ?>" required maxlength="100">
                    <?= field_error($errors, 'last_name') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">Email *</label>
                    <input class="form-control<?= invalid($errors, 'email') ?>" type="email" id="email" name="email" value="<?= e($val('email')) ?>" required maxlength="190">
                    <?= field_error($errors, 'email') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="phone">Téléphone</label>
                    <input class="form-control<?= invalid($errors, 'phone') ?>" id="phone" name="phone" value="<?= e($val('phone')) ?>" maxlength="30">
                    <?= field_error($errors, 'phone') ?>
                </div>
                <div class="col-md-4"><?= $select('department_id', 'Service', $departments, 'name') ?></div>
                <div class="col-md-4"><?= $select('position_id', 'Fonction', $positions, 'title') ?></div>
                <div class="col-md-4"><?= $select('manager_id', 'Responsable hiérarchique', $managers, 'name') ?></div>
            </div></div>
            <?php if ($isNew): ?>
                <div class="card mt-4"><div class="card-header">Activation du compte</div><div class="card-body">
                    <p class="small text-secondary">Par défaut, un lien d'activation valable 72 heures est envoyé par email. Si l'email n'est pas encore opérationnel, vous pouvez définir un mot de passe temporaire : l'utilisateur devra le changer à sa première connexion.</p>
                    <label class="form-label" for="temp_password">Mot de passe temporaire (facultatif)</label>
                    <input class="form-control<?= invalid($errors, 'temp_password') ?>" type="password" id="temp_password" name="temp_password" autocomplete="new-password">
                    <?= field_error($errors, 'temp_password') ?>
                </div></div>
            <?php endif; ?>
        </div>
        <div class="col-lg-4">
            <div class="card"><div class="card-header">Rôles *</div><div class="card-body">
                <?php foreach ($roles as $r): ?>
                    <?php $locked = $r['slug'] === 'super_admin' && !$actorIsSuper; ?>
                    <label class="kb-check-row" for="role<?= (int) $r['id'] ?>">
                        <input class="form-check-input" type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>" id="role<?= (int) $r['id'] ?>"
                            <?= in_array((int) $r['id'], $selectedRoles, true) ? ' checked' : '' ?><?= $locked ? ' disabled' : '' ?>>
                        <span>
                            <?= e($r['name']) ?>
                            <?php if ($r['description']): ?><span class="d-block small text-secondary"><?= e($r['description']) ?></span><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
                <?= field_error($errors, 'roles') ?>
            </div></div>
            <div class="d-grid gap-2 mt-4">
                <button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i> Enregistrer</button>
                <a class="btn btn-light" href="<?= e(url($isNew ? '/admin/users' : '/admin/users/' . $target['id'])) ?>">Annuler</a>
            </div>
        </div>
    </div>
</form>
