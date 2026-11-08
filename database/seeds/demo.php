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

$db->transaction(function () use ($db, $hash, $now, $app) {
    $depts = [];
    foreach (['Direction générale' => 'DG', 'Travaux' => 'TRV', 'Études techniques' => 'ETU', 'Ressources humaines' => 'RH', 'Comptabilité' => 'CPT', 'Informatique' => 'DSI'] as $name => $code) {
        $depts[$code] = $db->insert('departments', ['name' => $name, 'code' => $code, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }
    $positions = [];
    foreach (['Directeur général', 'Conducteur de travaux', 'Ingénieur études', 'Chargé RH', 'Comptable', 'Technicien informatique'] as $title) {
        $positions[$title] = $db->insert('positions', ['title' => $title, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }
    $roleId = static fn (string $slug) => (int) $db->value('SELECT id FROM roles WHERE slug = ?', [$slug]);
    // Numéros fictifs (plan de numérotation de démonstration).
    $people = [
        ['awa.diallo', 'Awa', 'Diallo', 'DG', 'Directeur général', 'directeur', '+223 20 00 00 01', '+223 70 00 00 01'],
        ['moussa.traore', 'Moussa', 'Traoré', 'TRV', 'Conducteur de travaux', 'chef_service', '+223 20 00 00 02', '+223 70 00 00 02'],
        ['fatou.kone', 'Fatou', 'Koné', 'ETU', 'Ingénieur études', 'collaborateur', '+223 20 00 00 03', null],
        ['ibrahim.sow', 'Ibrahim', 'Sow', 'RH', 'Chargé RH', 'admin', '+223 20 00 00 04', '+223 70 00 00 04'],
        ['mariam.cisse', 'Mariam', 'Cissé', 'CPT', 'Comptable', 'collaborateur', null, null],
        ['oumar.keita', 'Oumar', 'Keïta', 'DSI', 'Technicien informatique', 'collaborateur', '+223 20 00 00 06', null],
        ['seydou.coulibaly', 'Seydou', 'Coulibaly', 'TRV', 'Conducteur de travaux', 'collaborateur', '+223 20 00 00 07', '+223 70 00 00 07'],
    ];
    $ids = [];
    foreach ($people as [$login, $first, $last, $dept, $position, $role, $phone, $personal]) {
        $ids[$login] = $id = $db->insert('users', [
            'email' => "$login@demo.kibarou.local", 'password_hash' => $hash, 'first_name' => $first, 'last_name' => $last,
            'phone' => $phone, 'phone_personal' => $personal,
            'department_id' => $depts[$dept], 'position_id' => $positions[$position], 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId($role)]);
    }
    // Organisation : services rattachés à la direction, responsables de service, statuts du jour.
    foreach (['TRV', 'ETU', 'RH', 'CPT', 'DSI'] as $code) {
        $db->update('departments', ['parent_id' => $depts['DG']], ['id' => $depts[$code]]);
    }
    $db->update('departments', ['manager_user_id' => $ids['awa.diallo']], ['id' => $depts['DG']]);
    $db->update('departments', ['manager_user_id' => $ids['moussa.traore']], ['id' => $depts['TRV']]);
    $db->update('departments', ['manager_user_id' => $ids['ibrahim.sow']], ['id' => $depts['RH']]);
    $db->update('users', ['manager_id' => $ids['moussa.traore']], ['id' => $ids['seydou.coulibaly']]);
    $today = $app->presence()->today();
    $statuses = [
        ['moussa.traore', 'site', 'Résidence Les Palmiers', null],
        ['seydou.coulibaly', 'site', 'Pont de Sotuba', null],
        ['fatou.kone', 'office', null, null],
        ['mariam.cisse', 'leave', null, (new DateTimeImmutable($today))->modify('+5 days')->format('Y-m-d')],
    ];
    foreach ($statuses as [$login, $status, $site, $until]) {
        $db->insert('user_presence', ['user_id' => $ids[$login], 'status' => $status, 'site_label' => $site, 'start_date' => $today,
            'until_date' => $until, 'set_by' => $ids[$login], 'updated_at' => $now]);
    }
});

echo "Données de démonstration créées. Comptes *@demo.kibarou.local, mot de passe : $password\n";
