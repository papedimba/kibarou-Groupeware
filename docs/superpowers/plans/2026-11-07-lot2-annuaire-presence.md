# Lot 2 — Annuaire, organigramme et présence : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** annuaire consultable par tous, fiche, organigramme par service, présence (statut déclaré + pastille « en ligne »), deux numéros et photo de profil sûre.

**Architecture :** nouveau module `app/Modules/Directory/` (dépôt, service de présence, service de photos, contrôleurs) dans la structure existante ; pages rendues par le serveur ; recherche filtrée côté navigateur avec repli serveur ; une migration `0003`. Aucune nouvelle dépendance.

**Tech Stack :** PHP 8.2 natif, PDO MySQL/MariaDB, extension GD (WebP), Bootstrap 5.3.3, JS natif, PHPUnit 11, Playwright 1.63.

**Spec :** `docs/superpowers/specs/2026-11-07-annuaire-presence-design.md` (approuvée). Contexte : `PRODUCT.md`.

## Global Constraints

- Aucun script ni attribut `style` inline (CSP `script-src 'self'; style-src 'self'`) ; toute sortie via `e()`.
- Requêtes préparées uniquement ; dates stockées en UTC ; « aujourd'hui » de la présence = date dans `APP_TIMEZONE`.
- Contrôle d'autorisation côté serveur sur chaque route et chaque modification (`directory.view`, responsable §5.0, `users.manage`).
- « Responsable » de P = `users.manager_id` de P **ou** `departments.manager_user_id` du service de P (service direct uniquement).
- Statuts : `office` Au bureau · `site` Sur chantier · `travel` En déplacement · `leave` En congé · `absent` Absent ; `site_label` ≤ 120 car. obligatoire si `site` ; `note` ≤ 160 ; `until_date` entre aujourd'hui et aujourd'hui + 90 jours ; pas de déclaration à l'avance.
- « En ligne » = session active depuis moins de 5 minutes ; aucune heure de dernière activité affichée.
- Photo : ≤ 5 Mo ; MIME `finfo` ∈ {image/jpeg, image/png, image/webp} ; ≤ 6000×6000 ; sortie 256×256 WebP ; stockage `storage/private/avatars/` ; servie à `directory.view` seulement.
- WCAG 2.1 AA, cibles ≥ 44 px et texte 16 px sous 992 px ; textes en français, pronom neutre.
- Tous les tests existants restent verts : `vendor/bin/phpunit` ; `node tests/e2e/smoke.js` (serveur lancé).

## Review Focus

1. **Bascule de jour selon le fuseau** : un statut posé hier (jour local) ne s'affiche plus aujourd'hui, même si le nettoyage n'est pas passé → test `PresenceTest::testYesterdayStatusIsNotShown` (tâche 3).
2. **Droits recalculés quand l'organisation change** : si une personne quitte le poste de responsable de service, elle perd immédiatement le numéro personnel et la modification de statut → test `DirectoryTest::testResponsableRightsFollowOrganisation` (tâche 2).
3. **Mise à jour groupée avec un identifiant hors équipe** (formulaire falsifié) : refusée en bloc (403), aucune modification → test `PresenceTest::testBulkRejectsForeignIds` (tâche 4).
4. **Recherche avec accents** : `?q=keita` trouve « Keïta » côté serveur ; le filtre navigateur fait de même → test `DirectoryTest::testServerSearchIgnoresAccents` (tâche 1) + étape smoke (tâche 7).
5. **Fichier piégé déguisé en image** (PHP renommé `.jpg`, image dont GD refuse le décodage, image géante) : refusé, rien n'est écrit dans `storage/private/avatars` → tests `AvatarTest` (tâche 6).

---

### Task 1 : socle, migration, annuaire et recherche

**Files :**
- Create : `database/migrations/0003_directory_presence.sql`, `app/Modules/Directory/DirectoryRepository.php`, `app/Modules/Directory/DirectoryController.php`, `app/Views/directory/index.php`
- Modify : `routes/web.php`, `app/Views/layouts/app.php` (entrée « Annuaire », icône `contact`), `public/assets/js/app.js` (filtre `[data-directory-filter]`), `public/assets/css/app.css`
- Test : `tests/Integration/DirectoryTest.php`, `tests/Unit/MigratorTest.php` (inchangé, doit passer)

**Migration 0003 :**
- `users.phone_personal VARCHAR(30) NULL` après `phone`.
- Table `user_presence` : `user_id` INT UNSIGNED PK FK users CASCADE ; `status` ENUM('office','site','travel','leave','absent') NOT NULL ; `site_label` VARCHAR(120) NULL ; `note` VARCHAR(160) NULL ; `start_date` DATE NOT NULL (jour local de la déclaration) ; `until_date` DATE NULL ; `set_by` INT UNSIGNED NULL FK users SET NULL ; `updated_at` DATETIME NOT NULL ; index `(until_date)`, `(start_date)`.
- Permission `directory.view` (module `directory`, « Consulter l'annuaire ») attribuée à admin, directeur, chef_service, collaborateur.
- `UPDATE modules SET is_enabled = 1 WHERE slug = 'directory'`.

**Interfaces :**
- Produces : `DirectoryRepository::__construct(Database $db)` ; `members(string $q = '', int $departmentId = 0): list<array>` — utilisateurs actifs (status active, deleted_at NULL) triés nom/prénom, colonnes `id, first_name, last_name, email, phone, phone_personal, avatar_path, department_id, department_name, position_title, manager_id` ; `find(int $id): ?array` (mêmes colonnes + `manager_name`, actifs seulement) ; `isResponsable(int $actorId, int $targetId): bool` (règle §5.0).
- Route `GET /directory` → `DirectoryController::index` sous `['auth', 'perm:directory.view']`.
- Attributs de vue : chaque entrée porte `data-search="<prénom nom fonction service chantier>"` en minuscules ; le champ de recherche porte `data-directory-filter`.

- [ ] **Step 1 : tests en échec** (`DirectoryTest`)
  - `testEveryRoleCanOpenDirectory` : collaborateur connecté → `/directory` 200, contient le nom d'un autre utilisateur actif, ne contient pas un utilisateur `status = disabled`.
  - `testCustomRoleWithoutPermissionIsDenied` : rôle personnalisé sans permission → 403.
  - `testServerSearchIgnoresAccents` : utilisateur « Oumar Keïta » ; `/directory?q=keita` contient « Keïta » et pas un autre nom.
  - `testDepartmentFilter` : `?department_id=<Travaux>` ne liste que les membres de Travaux.
  - `testNavShowsDirectory` : l'accueil contient `href="/directory"`.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter DirectoryTest` → FAIL (route absente).
- [ ] **Step 3 :** migration, dépôt, contrôleur, vue (cartes empilées sur mobile, boutons « Appeler »/« Email » ≥ 44 px, numéro professionnel uniquement), filtre JS (`normalize('NFD')`, suppression des diacritiques, `hidden` sur les entrées non concordantes, message « Aucun collaborateur ne correspond. »).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS (le bootstrap de test rejoue toutes les migrations).
- [ ] **Step 5 :** commit « Annuaire : migration, liste et recherche ».

### Task 2 : fiche, responsable et deux numéros

**Files :**
- Create : `app/Views/directory/show.php`
- Modify : `app/Modules/Directory/DirectoryController.php` (`show`), `routes/web.php`, `app/Modules/Users/ProfileController.php` + `app/Views/profile/show.php` (deux numéros), `app/Modules/Users/UserService.php` (règle `phone_personal => 'phone|max:30'`, libellé « Téléphone personnel ») + `app/Views/admin/users/form.php` + `app/Views/admin/users/show.php` (numéro personnel visible seulement avec `users.manage`), `app/Modules/Admin/AuditLabels.php` (`FIELDS['phone_personal'] = 'Téléphone personnel'`, `FIELDS['phone'] = 'Téléphone professionnel'`), libellé « Téléphone professionnel » partout où « Téléphone » est affiché
- Test : `tests/Integration/DirectoryTest.php`

**Interfaces :**
- Consumes : `DirectoryRepository::find`, `isResponsable` (tâche 1).
- Produces : `DirectoryController::canSeePersonalPhone(array $viewer, array $target): bool` (privée ; soi, responsable, `users.manage`) ; route `GET /directory/{id}`.

- [ ] **Step 1 : tests en échec**
  - `testPersonalPhoneVisibility` : cible avec `phone_personal = '+223 70 00 00 01'` ; visible pour elle-même, pour son responsable hiérarchique, pour le responsable de son service, pour un admin ; invisible pour un collègue (même service) et pour un directeur sans lien.
  - `testResponsableRightsFollowOrganisation` : responsable de service voit le numéro ; après `UPDATE departments SET manager_user_id = NULL`, ne le voit plus.
  - `testDisabledOrUnknownProfileIs404` : fiche d'un compte désactivé → 404 ; `/directory/999999` → 404.
  - `testWriteButtonHiddenWhileChatDisabled` : la fiche ne contient pas « Écrire ».
  - `testProfileSavesBothPhones` : POST `/profile` avec `phone` et `phone_personal` → enregistrés ; valeur invalide → erreur sur le bon champ.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter DirectoryTest` → FAIL.
- [ ] **Step 3 :** implémenter (fiche : photo/initiales, fonction, service lien vers `/directory/org#service-<id>`, responsable lien vers sa fiche, email, téléphones, emplacement du statut rempli à la tâche 3).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Fiche annuaire, responsable, téléphones professionnel et personnel ».

### Task 3 : présence (statut déclaré, pastille, nettoyage)

**Files :**
- Create : `app/Modules/Directory/PresenceService.php`, `app/Modules/Directory/PresenceController.php`, `app/Views/partials/presence_form.php`, `app/Views/partials/presence_badge.php`
- Modify : `routes/web.php`, `app/Modules/Directory/DirectoryController.php` (statuts + en ligne dans liste et fiche), `app/Views/directory/index.php`, `app/Views/directory/show.php`, `app/Modules/Dashboard/DashboardController.php` + `app/Views/dashboard/index.php` (sélecteur rapide en tête), `app/Views/profile/show.php`, `app/Services/Maintenance.php` + `bin/console`, `app/Modules/Admin/AuditLabels.php` (`presence.updated` → « Statut modifié »), `app/Views/admin/_audit_table.php` si besoin
- Test : `tests/Integration/PresenceTest.php`, `tests/Integration/InfrastructureTest.php`, `tests/Unit/AuditLabelsTest.php` (doit passer)

**Interfaces :**
- Consumes : `DirectoryRepository::isResponsable`.
- Produces :
  - `PresenceService::__construct(Database $db, DirectoryRepository $repo, Rbac $rbac, AuditLogger $audit, string $timezone)`
  - `const LABELS = ['office' => 'Au bureau', 'site' => 'Sur chantier', 'travel' => 'En déplacement', 'leave' => 'En congé', 'absent' => 'Absent']`
  - `today(): string` (Y-m-d dans `$timezone`)
  - `current(array $userIds): array<int, array{status:string,label:string,site_label:?string,note:?string,until_date:?string}>` — uniquement les statuts valides (`COALESCE(until_date, start_date) >= today`)
  - `onlineIds(): array<int,true>`
  - `canEdit(array $actor, int $targetId): bool`
  - `set(array $actor, int $targetId, array $input): void` — valide, `REPLACE` la ligne, `start_date = today`, audit `presence.updated` si `actor ≠ target` ; lève `HttpException(403)` / `ValidationException`
  - `clear(array $actor, int $targetId): void`
  - `Maintenance::__construct(..., string $today)` ; `run()` ajoute `'presence'` : suppression des statuts expirés et de ceux des comptes désactivés.
- Routes (`auth` + `perm:directory.view`) : `POST /presence` (champs `status`, `site_label`, `note`, `until_date`, `user_id` facultatif = soi) ; `POST /presence/clear` (`user_id` facultatif). Redirection vers l'URL interne `return_to` si elle commence par `/` (même règle que la connexion), sinon `/`.

- [ ] **Step 1 : tests en échec** (`PresenceTest`)
  - `testSetOwnStatusShowsInDirectory` : POST `/presence` `status=site&site_label=Résidence Les Palmiers` → l'annuaire affiche « Sur chantier » et « Résidence Les Palmiers ».
  - `testSiteRequiresLabel` / `testUntilDateBounds` : `site` sans libellé → erreur ; `until_date` hier ou dans 91 jours → erreur.
  - `testYesterdayStatusIsNotShown` : ligne insérée avec `start_date` = hier, `until_date` NULL → « Statut non renseigné ».
  - `testUntilDateKeepsStatus` : `start_date` = hier, `until_date` = demain → affiché.
  - `testOnlyAllowedPeopleCanEditOthers` : collègue → 403 ; responsable de service → OK et une ligne `presence.updated` avec `entity_id` = cible ; admin → OK ; soi-même → OK sans ligne d'audit.
  - `testOnlineBadge` : session de la cible avec `last_activity_at` = maintenant → « en ligne » ; il y a 10 min → absent ; aucune heure affichée.
  - `InfrastructureTest::testMaintenancePurgesExpiredPresence` : statut expiré et statut d'un compte désactivé supprimés, statut valide conservé.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter 'PresenceTest|Maintenance'` → FAIL.
- [ ] **Step 3 :** implémenter ; sélecteur rapide : 5 boutons ≥ 44 px (radio stylés) + champs chantier/note/date affichés pour `site`, `travel`, `leave` (JS, visibles par défaut sans JS) ; pastille = point coloré + texte masqué visuellement « en ligne ».
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Présence : statut déclaré, pastille en ligne, nettoyage nocturne ».

### Task 4 : « Mon équipe aujourd'hui » et mise à jour groupée

**Files :**
- Modify : `app/Modules/Directory/PresenceService.php`, `app/Modules/Directory/PresenceController.php`, `routes/web.php`, `app/Modules/Dashboard/DashboardController.php`, `app/Views/dashboard/index.php`
- Test : `tests/Integration/PresenceTest.php`

**Interfaces :**
- Produces : `PresenceService::team(array $actor): list<array>` (personnes actives dont l'acteur est responsable, hors lui-même, avec statut courant) ; `bulkSet(array $actor, list<int> $targetIds, array $input): int` — tout ou rien : un seul identifiant non éditable → `HttpException(403)` sans écriture ; transaction ; une ligne d'audit par personne. Route `POST /presence/team` (champs du statut + `user_ids[]`).

- [ ] **Step 1 : tests en échec**
  - `testTeamBlockListsMembers` : responsable de service → l'accueil contient « Mon équipe aujourd'hui » et ses membres ; collaborateur sans équipe → pas de bloc.
  - `testMembersWithValidStatusAreUncheckedByDefault` : membre « En congé » jusqu'à demain → sa case n'a pas `checked`, son statut est affiché ; membre sans statut → `checked`.
  - `testBulkUpdate` : 2 membres cochés → 2 statuts « Sur chantier : Résidence X », 2 lignes d'audit.
  - `testBulkRejectsForeignIds` : un identifiant hors équipe dans `user_ids[]` → 403, aucun statut écrit.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter PresenceTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Présence : équipe du jour et mise à jour groupée ».

### Task 5 : organigramme par service

**Files :**
- Create : `app/Views/directory/org.php`
- Modify : `app/Modules/Directory/DirectoryController.php` (`org`), `app/Modules/Directory/DirectoryRepository.php`, `routes/web.php`, `public/assets/js/app.js` (repli < 768 px des `<details>` sans attribut `data-mine`)
- Test : `tests/Integration/DirectoryTest.php`

**Interfaces :**
- Produces : `DirectoryRepository::orgTree(): list<array{id:int,name:string,manager:?array,members:list<array>,children:list<...>}>` (services actifs ; responsable en tête, non répété dans les membres ; service vide sans sous-service omis) + `withoutDepartment(): list<array>`. Route `GET /directory/org` ; chaque service a `id="service-<id>"` ; le service de l'utilisateur porte `open data-mine`, les autres `open` (le script referme sur mobile).

- [ ] **Step 1 : tests en échec**
  - `testOrgChartShowsHierarchyAndHeads` : « Direction générale » parent de « Travaux » → « Travaux » est imbriqué dans le `<details>` de la direction ; le responsable de Travaux apparaît une seule fois dans ce service.
  - `testOrgChartGroupsPeopleWithoutService` : utilisateur sans service → section « Sans service ».
  - `testEmptyLeafServiceIsHidden` : service actif sans membre ni enfant → absent.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter DirectoryTest` → FAIL.
- [ ] **Step 3 :** implémenter (arbre construit en PHP à partir de deux requêtes : services, membres).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Organigramme par service ».

### Task 6 : photo de profil

**Files :**
- Create : `app/Modules/Directory/AvatarService.php`, `tests/Integration/AvatarTest.php`, `tests/fixtures/` (images de test générées dans le test par GD, plus un faux `.jpg` contenant du PHP)
- Modify : `app/Core/helpers.php` (`avatar()`), toutes les vues qui affichent des initiales (layout, annuaire, fiche, organigramme, fiche admin), `app/Modules/Users/ProfileController.php` + `app/Views/profile/show.php`, `app/Modules/Users/UsersController.php` + `app/Views/admin/users/show.php`, `app/Modules/Directory/DirectoryController.php` (`photo`), `routes/web.php`, `app/Modules/Admin/AuditLabels.php` (`profile.photo_updated` « Photo modifiée », `user.photo_removed` « Photo retirée »), `docs/DEPLOYMENT.md` (checklist : GD avec WebP)
- Test : `tests/Integration/AvatarTest.php`, `tests/Unit/HelpersTest.php`

**Interfaces :**
- Produces : `AvatarService::__construct(Database $db, string $storageDir)` ; `store(int $userId, array $file): void` (`$file` au format `$_FILES['photo']` ; lit `tmp_name`, n'utilise pas `move_uploaded_file` : la sortie est un nouveau fichier écrit par GD ; lève `ValidationException(['photo' => …])`) ; `remove(int $userId): void` ; `absolutePath(string $relative): string` (refuse tout chemin hors `avatars/`). `avatar(array $user, string $class = ''): string` — `<img src="/directory/{id}/photo?v=<jeton>" alt="" class="kb-avatar …">` si `avatar_path`, sinon les initiales (`kb-avatar`). Routes : `POST /profile/photo`, `POST /profile/photo/delete` (auth) ; `POST /admin/users/{id}/photo`, `POST /admin/users/{id}/photo/delete` (`users.manage`) ; `GET /directory/{id}/photo` (`directory.view`, en-têtes : `Content-Type: image/webp`, `Cache-Control: private, max-age=86400`).
- Les formulaires d'envoi utilisent `enctype="multipart/form-data"` ; `Request::fromGlobals` transmet déjà `$_FILES`.

- [ ] **Step 1 : tests en échec** (`AvatarTest`)
  - `testValidJpegIsStoredAs256Webp` : JPEG 800×600 généré par GD avec un en-tête EXIF ajouté → stocké ; fichier servi : `getimagesize` = 256×256, MIME `image/webp`, la chaîne `Exif` absente des octets.
  - `testFakeImageIsRejected` : fichier `<?php echo 1;` nommé `photo.jpg` → erreur « photo », aucun fichier créé dans `avatars/`.
  - `testTooLargeIsRejected` : `size` > 5 Mo → erreur, rien écrit.
  - `testHugeDimensionsAreRejected` : PNG 6001×10 → erreur.
  - `testReplaceDeletesOldFile` : deux envois → un seul fichier pour cet utilisateur.
  - `testPhotoRequiresLogin` : sans session → redirection `/login` ; avec `directory.view` → 200.
  - `testAdminCanRemovePhoto` : admin → `avatar_path` NULL, fichier supprimé, audit `user.photo_removed`.
  - `HelpersTest::testAvatarFallsBackToInitials`.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter 'AvatarTest|testAvatar'` → FAIL.
- [ ] **Step 3 :** implémenter (recadrage carré centré, `imagecopyresampled`, `imagewebp($img, $path, 82)`, nom `<id>-<16 hex aléatoires>.webp`, `mkdir 0750` si absent ; sans GD : `ValidationException` « Le serveur ne peut pas traiter les images. »).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Photo de profil sûre et privée ».

### Task 7 : démonstration, documentation, interface et vérification

**Files :**
- Modify : `database/seeds/demo.php` (statuts du jour variés, numéros, responsables de service), `tests/e2e/smoke.js`, `docs/DATABASE.md`, `docs/SECURITY.md`, `docs/ADMINISTRATION.md`, `PLAN.md`

- [ ] **Step 1 : test en échec** — `smoke.js` : sur iPhone 13, `/directory` → taper « keita » dans le champ de recherche masque les autres cartes sans rechargement ; cibles ≥ 44 px sur `/directory`, `/directory/org`, une fiche ; sur l'accueil, choisir « Au bureau » dans le sélecteur rapide puis l'annuaire montre « Au bureau » pour soi.
- [ ] **Step 2 :** lancer `smoke.js` → FAIL (avant les ajustements d'interface).
- [ ] **Step 3 :** ajustements d'interface (lecture préalable de `reference/craft-floor.md` d'Impeccable), seed, documentation.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; `smoke.js` → « Smoke UI OK » ; `impeccable detect --json` sur le HTML rendu de `/directory`, une fiche, `/directory/org`, l'accueil → aucun avertissement hors faux positifs connus.
- [ ] **Step 5 :** commit « Lot 2 : démonstration, documentation et vérifications » ; push.
