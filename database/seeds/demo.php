<?php

declare(strict_types=1);

/**
 * Données de démonstration — développement et recette uniquement (refusé en production).
 * Exécuté par `bin/console seed:demo`. Le mot de passe commun est généré et affiché une fois.
 *
 * @var App\Core\App $app
 */

use App\Modules\Auth\PasswordPolicy;

$db = $app->db();
if ((int) $db->value("SELECT COUNT(*) FROM users WHERE email LIKE '%@demo.kibarou.local'") > 0) {
    echo "Données de démonstration déjà présentes.\n";
    return;
}

$password = 'Demo-' . bin2hex(random_bytes(4)) . '1';
$hash = PasswordPolicy::hash($password);
$now = now_utc();

$db->transaction(function () use ($db, $hash, $now) {
    $depts = [];
    foreach (['Direction générale' => 'DG', 'Travaux' => 'TRV', 'Études techniques' => 'ETU', 'Ressources humaines' => 'RH', 'Comptabilité' => 'CPT', 'Informatique' => 'DSI'] as $name => $code) {
        $depts[$code] = $db->insert('departments', ['name' => $name, 'code' => $code, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }
    $positions = [];
    foreach (['Directeur général', 'Conducteur de travaux', 'Ingénieur études', 'Chargé RH', 'Comptable', 'Technicien informatique'] as $title) {
        $positions[$title] = $db->insert('positions', ['title' => $title, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }
    $roleId = static fn (string $slug) => (int) $db->value('SELECT id FROM roles WHERE slug = ?', [$slug]);
    $people = [
        ['awa.diallo', 'Awa', 'Diallo', 'DG', 'Directeur général', 'directeur'],
        ['moussa.traore', 'Moussa', 'Traoré', 'TRV', 'Conducteur de travaux', 'chef_service'],
        ['fatou.kone', 'Fatou', 'Koné', 'ETU', 'Ingénieur études', 'collaborateur'],
        ['ibrahim.sow', 'Ibrahim', 'Sow', 'RH', 'Chargé RH', 'admin'],
        ['mariam.cisse', 'Mariam', 'Cissé', 'CPT', 'Comptable', 'collaborateur'],
        ['oumar.keita', 'Oumar', 'Keïta', 'DSI', 'Technicien informatique', 'collaborateur'],
    ];
    foreach ($people as [$login, $first, $last, $dept, $position, $role]) {
        $id = $db->insert('users', [
            'email' => "$login@demo.kibarou.local", 'password_hash' => $hash, 'first_name' => $first, 'last_name' => $last,
            'department_id' => $depts[$dept], 'position_id' => $positions[$position], 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId($role)]);
    }
});

echo "Données de démonstration créées. Comptes *@demo.kibarou.local, mot de passe : $password\n";
