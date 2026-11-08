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
    // Notifications de démonstration (sans email : elles sont déjà « traitées »).
    foreach ([['seydou.coulibaly', 'Votre statut a été modifié par Moussa Traoré : Sur chantier'], ['mariam.cisse', 'Votre compte a été réactivé']] as [$login, $title]) {
        $db->insert('notifications', ['user_id' => $ids[$login], 'type' => str_contains($title, 'statut') ? 'presence.changed' : 'account.enabled',
            'title' => $title, 'url' => '/profile', 'created_at' => $now, 'email_status' => 'digested']);
    }
    foreach ($statuses as [$login, $status, $site, $until]) {
        $db->insert('user_presence', ['user_id' => $ids[$login], 'status' => $status, 'site_label' => $site, 'start_date' => $today,
            'until_date' => $until, 'set_by' => $ids[$login], 'updated_at' => $now]);
    }

    // Messagerie : passe par ChatService pour que le journal d'évènements et les non-lus soient cohérents.
    $u = static fn (string $login) => $db->fetch('SELECT * FROM users WHERE id = ?', [$ids[$login]]);
    $chat = $app->chat();
    $direct = $chat->directWith($u('moussa.traore'), $ids['seydou.coulibaly']);
    $chat->send($u('moussa.traore'), $direct, "Bonjour Seydou, où en est le coulage de la dalle au Pont de Sotuba ?");
    $chat->send($u('seydou.coulibaly'), $direct, "Ferraillage terminé ce matin, coulage prévu demain à 7 h si le béton est livré.");
    $group = $chat->createGroup($u('moussa.traore'), 'Chantier Résidence Les Palmiers', [$ids['seydou.coulibaly'], $ids['fatou.kone']]);
    $chat->send($u('fatou.kone'), $group, "Les plans d'exécution du bâtiment B sont à jour :\nhttps://kibarou.example/plans/palmiers-b");
    $meeting = $chat->send($u('moussa.traore'), $group, "Merci Fatou. Réunion de chantier jeudi à 9 h sur place.");
    // Pièces jointes de démonstration : une photo générée et un tableau Excel minimal.
    $photo = imagecreatetruecolor(1600, 1200);
    imagefill($photo, 0, 0, imagecolorallocate($photo, 168, 162, 150));
    for ($i = 0; $i < 12; $i++) {
        imagefilledrectangle($photo, 100 + $i * 120, 300, 160 + $i * 120, 1100, imagecolorallocate($photo, 90, 70, 60));
    }
    $photoFile = tempnam(sys_get_temp_dir(), 'kbdemo');
    imagejpeg($photo, $photoFile, 85);
    $sheetFile = tempnam(sys_get_temp_dir(), 'kbdemo');
    $zip = new ZipArchive();
    $zip->open($sheetFile, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>');
    $zip->close();
    $attachmentIds = [];
    foreach ([['Ferraillage bâtiment B.jpg', $photoFile], ['Suivi béton semaine 45.xlsx', $sheetFile]] as [$name, $file]) {
        $attachmentIds[] = (int) $chat->uploadAttachment($u('seydou.coulibaly'), $group, ['name' => $name, 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)])['id'];
    }
    $chat->send($u('seydou.coulibaly'), $group, 'Ferraillage du bâtiment B terminé, et le suivi béton à jour.', null, $attachmentIds, $meeting['message_id']);
    $last = (int) $db->value('SELECT MAX(id) FROM messages');
    $chat->react($u('moussa.traore'), $last, '👍');
    $chat->react($u('fatou.kone'), $last, '✅');
    // Salons : un chantier ouvert, un salon archivé ; salons de service d'après l'annuaire.
    $salon = $app->channels()->create($u('moussa.traore'), 'Chantier Pont de Sotuba', 'Ouvrage d\'art, lot 3');
    $app->channels()->join($u('seydou.coulibaly'), $salon);
    $app->channels()->join($u('fatou.kone'), $salon);
    $chat->send($u('moussa.traore'), $salon, 'Bienvenue dans le salon du chantier : toutes les décisions passent par ici.');
    $chat->send($u('seydou.coulibaly'), $salon, 'Coffrage des piles P3 et P4 validé par le bureau de contrôle.');
    $old = $app->channels()->create($u('moussa.traore'), 'Matériel et engins', 'Réservations de la grue (2025)');
    $chat->send($u('moussa.traore'), $old, 'La grue est rendue au loueur, salon archivé.');
    $app->channels()->archive($u('moussa.traore'), $old);
    $app->channels()->syncDepartments();
    // Calendrier : réunion de chantier hebdomadaire (service Travaux), visite d'une journée, rendez-vous privé.
    $monday = new DateTimeImmutable('monday next week 08:00', new DateTimeZone((string) $app->config('app.timezone')));
    $app->events()->create($u('moussa.traore'), ['title' => 'Réunion de chantier', 'location' => 'Base vie, Résidence Les Palmiers',
        'start' => $monday->format('Y-m-d H:i'), 'end' => $monday->modify('+1 hour')->format('Y-m-d H:i'), 'reminder' => '15',
        'departments' => [(string) $depts['TRV']], 'participants' => [(string) $ids['fatou.kone']],
        'repeat' => 'weekly', 'weekdays' => ['1'], 'until' => $monday->modify('+12 weeks')->format('Y-m-d')]);
    $app->events()->create($u('seydou.coulibaly'), ['title' => 'Visite du bureau de contrôle', 'location' => 'Pont de Sotuba', 'all_day' => '1',
        'start' => $monday->modify('+2 days')->format('Y-m-d'), 'end' => $monday->modify('+2 days')->format('Y-m-d'), 'reminder' => '1440',
        'participants' => [(string) $ids['moussa.traore']]]);
    $app->events()->create($u('awa.diallo'), ['title' => 'Rendez-vous banque', 'is_private' => '1',
        'start' => $monday->modify('+1 day')->setTime(15, 0)->format('Y-m-d H:i'), 'end' => $monday->modify('+1 day')->setTime(16, 0)->format('Y-m-d H:i')]);
    $db->run("UPDATE notifications SET email_status = 'digested' WHERE type LIKE 'calendar.%'");
    // Aucun email réel vers les adresses de démonstration (invitations envoyées immédiatement par le calendrier).
    $db->run("DELETE FROM mail_queue WHERE to_email LIKE '%@demo.kibarou.local'");
    // Aucun email de démonstration : les notifications de messages sont considérées comme traitées.
    $db->run("UPDATE notifications SET email_status = 'digested' WHERE type = 'chat.message'");
});

echo "Données de démonstration créées. Comptes *@demo.kibarou.local, mot de passe : $password\n";
