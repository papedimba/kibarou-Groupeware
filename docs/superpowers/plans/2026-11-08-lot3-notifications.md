# Lot 3 — Notifications : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** centrale de notifications (en base, dans l'application, alertes navigateur PC/Android, emails immédiats ou récapitulatif quotidien) et ses trois premiers branchements.

**Architecture :** module `app/Modules/Notifications/` (catalogue, centrale, contrôleurs, récapitulatif) ; envoi des emails par la `mail_queue` et `cron:run` existants ; interrogation légère `GET /api/notifications` toutes les 60 s ; service worker d'affichage `public/sw.js`. Migration `0004`. Aucune dépendance nouvelle.

**Tech Stack :** PHP 8.2 natif, MySQL/MariaDB, JS natif (Fetch, Notification, Service Worker), Bootstrap 5.3.3, PHPUnit 11, Playwright 1.63.

**Spec :** `docs/superpowers/specs/2026-11-08-notifications-design.md` (approuvée). Contexte : `PRODUCT.md`.

## Global Constraints

- Aucun envoi SMTP pendant une requête HTTP : tout email passe par `MailQueue::push`.
- Aucun script ni style inline ; CSP inchangée sauf ajout de `worker-src 'self'`.
- `url` d'une notification : chemin interne commençant par `/` (sinon ignorée) ; aucune notification n'est lisible ou modifiable par un autre que son destinataire.
- « 7 h » et « aujourd'hui » dans `APP_TIMEZONE` ; stockage UTC.
- Interrogation : 60 s, uniquement onglet visible ; les appels `/api/*` ne prolongent pas la session et ne consomment pas les messages flash.
- Catalogue lot 3 : `security.password_changed` (immédiat, obligatoire, navigateur non), `account.enabled` (immédiat, navigateur non), `presence.changed` (récapitulatif, navigateur oui).
- Conservation : lues > 90 jours et toutes > 365 jours supprimées.
- Textes en français, WCAG AA, cibles ≥ 44 px ; tests existants verts (`vendor/bin/phpunit` et `node tests/e2e/smoke.js`, les deux à chaque fin de tâche).

## Review Focus

1. **Un appel d'interrogation juste après une redirection** ne doit pas « manger » le message flash destiné à la page suivante → test `NotificationApiTest::testPollingDoesNotConsumeFlash` (tâche 4).
2. **Session expirée pendant que l'onglet reste ouvert** : l'API renvoie 401 JSON (pas une page HTML de connexion), le script s'arrête → test `NotificationApiTest::testExpiredSessionReturns401Json` (tâche 4).
3. **CRON exécuté plusieurs fois après 7 h ou rattrapant une journée manquée** : un seul récapitulatif par jour local et par personne → test `DigestTest::testDigestRunsOncePerLocalDay` (tâche 5).
4. **Identifiant de notification d'un autre utilisateur** dans `/notifications/{id}/open` : 404, rien n'est modifié → test `NotificationCenterTest::testCannotOpenSomeoneElsesNotification` (tâche 2).
5. **Utilisateur devant changer son mot de passe** pendant que la page interroge : 403 JSON, pas de redirection HTML → test `NotificationApiTest::testMustChangePasswordGets403Json` (tâche 4).

---

### Task 1 : schéma, catalogue et centrale d'envoi

**Files :**
- Create : `database/migrations/0004_notifications.sql`, `app/Modules/Notifications/NotificationTypes.php`, `app/Modules/Notifications/Notifier.php`
- Modify : `app/Core/App.php` (`notifier()`), `app/Services/Maintenance.php` (purge), `tests/Integration/InfrastructureTest.php`
- Test : `tests/Integration/NotifierTest.php`

**Migration 0004 :** tables `notifications` et `notification_preferences` exactement comme la spec §5.1 (`email_status` ENUM('none','queued','digest_pending','digested') NOT NULL DEFAULT 'none' ; index `(user_id, read_at, id)` et `(email_status, user_id)` ; FK user CASCADE, actor SET NULL) ; `notification_preferences.email` ENUM('immediate','digest','off') NOT NULL, `browser` TINYINT(1) NOT NULL, PK `(user_id, type)`.

**Interfaces :**
- Produces :
  - `NotificationTypes::all(): array<string, array{label:string, email:string, browser:bool, mandatory:bool}>` (les 3 types de la spec §5.2) ; `NotificationTypes::get(string $type): array` (lève `\InvalidArgumentException` si inconnu).
  - `Notifier::__construct(Database $db, MailQueue $mail, string $appUrl)` ;
    `notify(int $userId, string $type, string $title, ?string $url = null, ?string $body = null, ?int $actorId = null): ?int` (id créé, ou null si destinataire inactif) ;
    `preferences(int $userId): array<string, array{email:string, browser:bool, mandatory:bool, label:string}>` (catalogue fusionné avec les lignes de préférences ; obligatoire ⇒ `email = immediate`) ;
    `unreadCount(int $userId): int`.
  - `App::notifier(): Notifier`.
  - Email immédiat : sujet = titre ; corps = titre, précision, `APP_URL . url` si url, ligne « Gérer mes notifications : {APP_URL}/profile/notifications ».
  - `Maintenance::run()` ajoute `'notifications'`.

- [ ] **Step 1 : tests en échec** (`NotifierTest`)
  - `testImmediateTypeQueuesEmail` : `notify($u, 'account.enabled', 'Votre compte a été réactivé', '/')` → 1 ligne `notifications` `email_status = queued`, 1 ligne `mail_queue` dont le corps contient `https://gw.test/`.
  - `testDigestTypeDoesNotQueueEmail` : `presence.changed` → `digest_pending`, 0 email.
  - `testPreferenceOffAndMandatoryOverride` : préférence `account.enabled = off` → `none`, 0 email ; préférence `security.password_changed = off` (insérée en base) → quand même `queued`.
  - `testUnknownTypeThrows` ; `testInactiveRecipientIsIgnored` (retour null, 0 ligne) ; `testExternalUrlIsDropped` (`https://evil.test` → `url` NULL).
  - `InfrastructureTest::testMaintenancePurgesOldNotifications` : lue il y a 91 jours et non lue il y a 366 jours supprimées ; lue il y a 10 jours conservée.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter 'NotifierTest|OldNotifications'` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Notifications : schéma, catalogue et centrale d'envoi ».

### Task 2 : cloche et page « Mes notifications »

**Files :**
- Create : `app/Modules/Notifications/NotificationsController.php`, `app/Views/notifications/index.php`
- Modify : `app/Views/layouts/app.php` (cloche), `app/Core/Controller.php` (partage `unreadCount` dans `view()`), `routes/web.php`, `public/assets/css/app.css`, `app/Core/helpers.php` (`relative_time(string $utc): string`)
- Test : `tests/Integration/NotificationCenterTest.php`, `tests/Unit/HelpersTest.php`

**Interfaces :**
- Consumes : `Notifier::notify`, `unreadCount`.
- Produces : routes (`auth`) `GET /notifications` (pagination 25), `POST /notifications/{id}/open` (marque lue, redirige vers `url` ou `/notifications`), `POST /notifications/read-all`. Cloche : lien `/notifications` avec `aria-label="Notifications, N non lues"`, pastille `data-unread-badge` (masquée à 0, « 99+ »), attribut `data-last-id` sur `<body>` = id de la dernière notification de l'utilisateur (0 sinon) pour la tâche 4.
- `relative_time` : « à l'instant » (< 1 min), « il y a N min » (< 60), « il y a N h » (< 24 h et même jour local), « hier », puis `d/m/Y`.

- [ ] **Step 1 : tests en échec**
  - `testBellShowsUnreadCount` : 2 notifications non lues → l'accueil contient `Notifications, 2 non lues` ; 0 → pastille absente.
  - `testListShowsOwnNotificationsNewestFirst` : 2 notifications pour moi, 1 pour un autre → seules les miennes, la plus récente d'abord ; état vide « Aucune notification pour l'instant. ».
  - `testOpenMarksReadAndRedirects` : `POST /notifications/{id}/open` → redirection vers son `url`, `read_at` non nul.
  - `testCannotOpenSomeoneElsesNotification` : → 404, `read_at` toujours NULL.
  - `testReadAll` : toutes mes notifications lues, pas celles des autres.
  - `HelpersTest::testRelativeTime` (cas « à l'instant », « il y a 5 min », « hier »).
- [ ] **Step 2 :** `vendor/bin/phpunit --filter 'NotificationCenterTest|testRelativeTime'` → FAIL.
- [ ] **Step 3 :** implémenter (lecture préalable de `reference/craft-floor.md` d'Impeccable ; non-lue = gras + point + texte masqué « non lue »).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Notifications : cloche et page Mes notifications ».

### Task 3 : préférences

**Files :**
- Create : `app/Views/notifications/preferences.php`
- Modify : `app/Modules/Notifications/NotificationsController.php`, `app/Modules/Notifications/Notifier.php` (`savePreferences`), `routes/web.php`, `app/Views/profile/show.php` (lien), `app/Views/notifications/index.php` (lien)
- Test : `tests/Integration/NotificationCenterTest.php`

**Interfaces :**
- Produces : `Notifier::savePreferences(int $userId, array $input): void` — `$input['email'][type] ∈ {immediate, digest, off}`, `$input['browser'][type]` coché ou non ; types inconnus ignorés ; types obligatoires jamais enregistrés. Routes (`auth`) `GET /profile/notifications`, `POST /profile/notifications`.
- La page contient le bouton `data-enable-alerts` « Activer les alertes du navigateur » et la zone `data-alerts-status` (texte rempli par la tâche 4).

- [ ] **Step 1 : tests en échec**
  - `testPreferencesSaveAndApply` : POST `email[presence.changed]=immediate` → une notification `presence.changed` est ensuite `queued`.
  - `testMandatoryTypeCannotBeChanged` : POST `email[security.password_changed]=off` → aucune ligne enregistrée pour ce type ; la page affiche « Alerte de sécurité, toujours envoyée par email ».
  - `testInvalidValueRejected` : `email[account.enabled]=sms` → valeur ignorée (préférence inchangée).
- [ ] **Step 2 :** `vendor/bin/phpunit --filter NotificationCenterTest` → FAIL.
- [ ] **Step 3 :** implémenter (tableau : une ligne par type, `<select>` email, case navigateur dans une `.kb-check-row` ; sur mobile, empilé).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Notifications : préférences par type et par canal ».

### Task 4 : interrogation, session passive et alertes navigateur

**Files :**
- Create : `public/sw.js`, `tests/Integration/NotificationApiTest.php`
- Modify : `app/Core/App.php` (appel passif pour `/api/`), `app/Core/Session.php` (`start(Request $request, bool $passive = false)`), `app/Core/Middleware.php` (JSON : 403 au lieu de la redirection « mot de passe à changer »), `app/Modules/Notifications/NotificationsController.php` (`poll`), `routes/web.php`, `app/Core/SecurityHeaders.php` (`worker-src 'self'`), `public/assets/js/app.js`, `tests/e2e/smoke.js`
- Test : `tests/Integration/NotificationApiTest.php`, `tests/e2e/smoke.js`

**Interfaces :**
- Produces : `GET /api/notifications?since=<int>` (`auth`) → `{"unread": int, "items": [{"id": int, "title": string, "url": ?string, "alert": bool}]}` (non lues, `id > since`, 10 au plus, ordre croissant). `Session::start(..., passive: true)` : ne fait pas tourner les messages flash et `save()` n'écrit rien ni ne pose de cookie si rien n'a changé. `App::handle` passe `passive = str_starts_with($request->path, '/api/')`.
- `public/sw.js` : écouteurs `notificationclick` (ferme la notification ; focalise un client de même origine et le dirige vers `event.notification.data.url`, sinon `clients.openWindow`) ; aucun `fetch` ni cache. Le script de page l'enregistre (`navigator.serviceWorker.register('/sw.js')`) quand l'API existe et affiche via `registration.showNotification('Kibarou Groupware', { body: title, data: { url } , tag: 'kb-' + id })` ; repli `new Notification(...)` sans service worker ; iPhone non installé (`/iPhone|iPad/` et pas `navigator.standalone`) → message de la spec dans `data-alerts-status`, bouton masqué.

- [ ] **Step 1 : tests en échec** (`NotificationApiTest`)
  - `testReturnsUnreadAfterSince` : 3 notifications ; `?since=<id du 1er>` → `items` = les 2 suivantes, `unread` = 3 ; `alert` vrai pour `presence.changed`, faux pour `account.enabled`.
  - `testPollingDoesNotExtendSession` : `last_activity_at` mis 10 min dans le passé → après l'appel, inchangé.
  - `testPollingDoesNotConsumeFlash` : flash posé (POST `/profile` avec téléphone valide) → appel API → la page suivante affiche « Profil mis à jour. ».
  - `testExpiredSessionReturns401Json` : session expirée → 401, `Content-Type` JSON.
  - `testMustChangePasswordGets403Json` : utilisateur `must_change_password = 1` → 403 JSON.
  - `SecurityHeaders` : la CSP d'une réponse contient `worker-src 'self'`.
  - `smoke.js` (Chromium, `context.grantPermissions(['notifications'])`) : connecté, créer une notification `presence.changed` pour soi via une action réelle (un responsable de démo modifie mon statut dans un second contexte), attendre ≤ 65 s → la pastille de la cloche affiche 1 sans rechargement, et `navigator.serviceWorker.ready.then(r => r.getNotifications())` contient une notification dont le corps contient « Votre statut a été modifié ».
- [ ] **Step 2 :** `vendor/bin/phpunit --filter NotificationApiTest` → FAIL ; smoke → FAIL.
- [ ] **Step 3 :** implémenter (intervalle 60 000 ms ; arrêt sur `document.hidden`, reprise immédiate à `visibilitychange` ; arrêt définitif sur 401/403 ; mise à jour de `data-last-id`).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Notifications : interrogation, alertes navigateur PC et Android ».

### Task 5 : récapitulatif quotidien

**Files :**
- Create : `app/Modules/Notifications/DigestService.php`, `tests/Integration/DigestTest.php`
- Modify : `bin/console` (`cron:run` appelle le récapitulatif avant l'envoi de la file), `app/Core/App.php` si besoin

**Interfaces :**
- Consumes : `MailQueue::push`.
- Produces : `DigestService::__construct(Database $db, MailQueue $mail, string $appUrl)` ; `run(\DateTimeImmutable $nowLocal): int` (nombre d'emails) — ne fait rien si `$nowLocal` < 07:00 ou si `settings.digest_last_date` = date locale ; sinon, dans une transaction, met la clé à jour puis, par personne active ayant des `digest_pending` non lues, un email « Vos notifications du {d/m/Y} » (20 titres max, puis « et N autres », lien `/notifications`) ; toutes les `digest_pending` (lues ou non) passent à `digested`. `cron:run` l'appelle avec `new \DateTimeImmutable('now', new \DateTimeZone(APP_TIMEZONE))`.

- [ ] **Step 1 : tests en échec** (`DigestTest`)
  - `testNothingBefore7am` : 06:59 → 0 email, statuts inchangés.
  - `testDigestGroupsUnreadPerUser` : 2 non lues + 1 lue pour A, 1 non lue pour B → 2 emails ; celui de A cite les 2 titres non lus et pas le lu ; les 4 passent à `digested`.
  - `testDigestRunsOncePerLocalDay` : deux appels à 07:05 et 09:00 le même jour → 1 seul email ; le lendemain 07:01 avec une nouvelle notification → 1 email.
  - `testDigestCapsAt20Titles` : 25 non lues → corps contient « et 5 autres ».
- [ ] **Step 2 :** `vendor/bin/phpunit --filter DigestTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Notifications : récapitulatif quotidien à 7 h ».

### Task 6 : branchements du lot 3

**Files :**
- Modify : `app/Modules/Auth/AuthService.php` (`changePassword`), `app/Modules/Auth/PasswordResetService.php` (`reset`), `app/Modules/Users/UserService.php` (`setStatus`), `app/Modules/Directory/PresenceService.php` (`write`, `clear`), constructeurs et fabriques dans `app/Core/App.php`, `app/Modules/Users/UsersController.php` si la fabrique change
- Test : `tests/Integration/NotificationTriggersTest.php`

**Interfaces :**
- Consumes : `Notifier::notify` (injecté par les fabriques de `App`).
- Titres exacts : spec §5.6. Changement imposé (`must_change_password` = 1 avant l'appel) → pas de notification.

- [ ] **Step 1 : tests en échec** (`NotificationTriggersTest`)
  - `testPasswordChangeNotifiesAndEmails` : changement volontaire → notification `security.password_changed` + email immédiat contenant « contactez immédiatement un administrateur ».
  - `testForcedFirstChangeDoesNotNotify` : utilisateur `must_change_password = 1` → 0 notification.
  - `testResetNotifies` : réinitialisation par lien → notification + email.
  - `testAccountEnabledNotifies` : admin réactive un compte → notification `account.enabled` + email ; désactivation → aucune notification.
  - `testPresenceChangedByOtherNotifies` : responsable pose « Sur chantier : Pont » → notification « Votre statut a été modifié par {Prénom Nom} : Sur chantier » ; soi-même → aucune ; effacement par le responsable → « Votre statut a été effacé par … » ; mise à jour groupée de 2 personnes → 2 notifications.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter NotificationTriggersTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Notifications : mot de passe, réactivation, statut ».

### Task 7 : démonstration, documentation et vérification

**Files :**
- Modify : `database/seeds/demo.php` (quelques notifications de démonstration), `docs/ARCHITECTURE.md` (§ notifications, interrogation), `docs/DATABASE.md`, `docs/SECURITY.md` (session passive, CSP `worker-src`), `docs/ADMINISTRATION.md`, `docs/DEPLOYMENT.md` (HTTPS requis pour les alertes et le service worker), `PLAN.md`

- [ ] **Step 1 :** vérifier le rendu (bureau et iPhone 13) de la cloche, de `/notifications` et de `/profile/notifications` ; corriger ce qui ne respecte pas `craft-floor.md`.
- [ ] **Step 2 :** `impeccable detect --json` sur le HTML rendu de ces pages → aucun avertissement hors faux positifs connus.
- [ ] **Step 3 :** seed vérifié sur une base vierge ; documentation.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → « Smoke UI OK ».
- [ ] **Step 5 :** commit « Lot 3 : démonstration, documentation et vérifications » ; push.
