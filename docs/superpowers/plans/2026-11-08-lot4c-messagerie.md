# Lot 4c — Messagerie (l'organisation) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** salons ouverts et salons de service, alertes par conversation, accusés de lecture et recherche dans la messagerie des lots 4a/4b.

**Architecture :** deux nouveaux types de conversation (`channel`, `department`) sur le modèle existant (journal d'évènements, interrogation, pièces jointes) ; nouveau `ChannelService` pour les règles propres aux salons et la synchronisation des salons de service ; `ChatService` garde l'envoi et les actions (refus si archivé, alertes filtrées par `notify`) ; positions de lecture renvoyées par l'interrogation pour la conversation ouverte ; recherche par `ChatRepository::search()`. Migration `0008`.

**Tech Stack :** PHP 8.2 natif, MySQL/MariaDB, JS natif, Bootstrap 5.3.3, Lucide, PHPUnit 11, Playwright (Chromium complet).

**Spec :** `docs/superpowers/specs/2026-11-08-messagerie-4c-design.md` (approuvée). Base : conceptions et code 4a/4b.

## Global Constraints

- Salons : nom 1–80 caractères, unique parmi les salons non archivés (« Un salon porte déjà ce nom. ») ; description ≤ 200 ; jamais supprimés.
- Historique complet pour `channel` et `department` : `joined_event_id = 0` ; à l'arrivée `last_read_event_id` = dernier évènement de la conversation et `entered_event_id` = même valeur (pour `direct`/`group`, `entered_event_id` = `joined_event_id`).
- Gestion des salons (renommer, description, archiver, désarchiver) : permission `chat.channels.manage` **et** (`admin.access` ou rôle `directeur` ou `created_by` = soi) ; possible sans être membre. Créer : `chat.channels.manage`. Permission donnée à `admin`, `chef_service`, `directeur`.
- Salon archivé : refus de message, réaction, modification, suppression, pièce jointe → `ValidationException(['body' => 'Ce salon est archivé : il n\'accepte plus de messages.'])` ; lisible ; visible de tous dans le filtre « Archivés » ; rejoindre/quitter permis.
- Salon de service : nom = nom du service ; membres = utilisateurs actifs avec `chat.use` dont `department_id` = service ; pas de départ volontaire, pas d'ajout manuel, pas de renommage ni d'archivage manuels ; service désactivé → archivé, réactivé → désarchivé.
- Alertes : `conversation_members.notify` (1 pour `direct`/`group`, 0 pour `channel`/`department` à l'arrivée) ; seuls les membres `notify = 1` sont notifiés ; le compteur du menu compte tous les non-lus.
- Pas d'évènement par lecture ; pas d'indicateur « en train d'écrire ».
- Recherche : 2 à 100 caractères, `LIKE` avec `%` `_` `\` échappés, messages non supprimés et noms de pièces jointes, conversations où l'on est membre actif, évènement d'origine > `joined_event_id`, 50 résultats les plus récents.
- Audit : `chat.channel_created`, `chat.channel_updated`, `chat.channel_archived` (sans contenu) ; rejoindre/quitter et synchronisation non audités ; aucune notification de synchronisation.
- Aucun script ni style inline ; WCAG AA ; cibles ≥ 44 px ; textes en français.
- Fin de chaque tâche : `vendor/bin/phpunit`, `php bin/console cron:run`, `node tests/e2e/smoke.js` et `node tests/e2e/chat-regressions.js` verts.

## Review Focus

1. **Recherche hors de son cercle** : non-membre d'un groupe, membre arrivé tard dans un groupe, message supprimé → rien ; membre arrivé tard dans un salon → tout l'historique → `ChatSearchTest::testSearchRespectsVisibility` (tâche 4).
2. **Mutation de service** : quitte l'ancien salon de service, entre dans le nouveau, immédiatement par l'administration et par `cron:run` après une modification directe en base ; compte désactivé retiré → `ChannelSyncTest` (tâche 2).
3. **Chef de service sur le salon d'un autre** : refus de renommer/archiver ; administrateur et directeur autorisés → `ChannelServiceTest::testManageRights` (tâche 1).
4. **Positions de lecture fuitées** : `reads` absent pour une conversation dont on n'est pas membre (paramètre `open` forgé) → `ChatReadsTest::testReadsOnlyForMembers` (tâche 3).
5. **Nouveau venu compté comme lecteur** : « Vu par N » ignore un membre arrivé après le message → `ChatReadsTest::testLateJoinerNotCountedAsReader` (tâche 3).

---

### Task 1 : salons (modèle, règles, écrans)

**Files :**
- Create : `database/migrations/0008_chat_channels.sql`, `app/Modules/Chat/ChannelService.php`, `app/Views/chat/channels.php`, `app/Views/chat/channel_form.php`, `tests/Integration/ChannelServiceTest.php`
- Modify : `app/Core/App.php` (`channels(): ChannelService`, remis à zéro dans `resetRequestState`), `app/Modules/Chat/ChatService.php` (refus si archivé dans `send`/`edit`/`delete`/`react`/`uploadAttachment` ; `notifyMembers` filtre `notify = 1` ; `group`-only règles inchangées), `app/Modules/Chat/ChatRepository.php` (`conversationsFor` : titre et icône des nouveaux types, colonnes `description`, `archived_at`, `department_id`, `notify`), `app/Modules/Chat/ChatController.php`, `routes/web.php`, `app/Views/chat/_list.php`, `app/Views/chat/index.php` (en-tête de salon, bandeau archivé, interrupteur « Alertes »), `app/Views/chat/_message.php` (« X a rejoint le salon » / « X a quitté le salon » / « … a archivé le salon »), `public/assets/js/chat.js` (mêmes textes système), `app/Modules/Admin/AuditLabels.php`, `public/assets/css/app.css`

**Migration 0008 :** `conversations.type` + `channel`, `department` ; `department_id` INT UNSIGNED NULL UNIQUE (FK departments SET NULL) ; `description` VARCHAR(200) NULL ; `archived_at` DATETIME NULL ; `conversation_members.notify` TINYINT(1) NOT NULL DEFAULT 1, `entered_event_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 (rempli = `joined_event_id` pour l'existant) ; `chat_events.type` + `archived`, `unarchived` ; permission `chat.channels.manage` (« Gérer les salons ») pour `admin`, `chef_service`, `directeur`. (La création des salons de service est faite par la tâche 2.)

**Interfaces :**
- Produces :
  - `ChannelService::__construct(Database $db, ChatRepository $repo, Rbac $rbac, AuditLogger $audit)` ; `App::channels()`
  - `create(array $actor, string $name, string $description): int` (403 sans permission ; créateur membre, `notify = 0`)
  - `join(array $actor, int $cid): void` (salon, archivé ou non ; membre déjà actif → rien) ; `leave(array $actor, int $cid): void` (salon seulement ; salon de service → 422 « On ne peut pas quitter le salon de son service. »)
  - `update(array $actor, int $cid, string $name, string $description): void` (évènement `renamed` si le nom change) ; `archive(array $actor, int $cid): void` ; `unarchive(array $actor, int $cid): void` — contrôle `canManage()` sinon 403 ; salon de service → 422 « Le salon d'un service suit l'annuaire. »
  - `canManage(array $user, array $conversation): bool`
  - `list(array $user, bool $archived): array` — `[{id, name, description, members, last_event_at, archived_at, member: bool, can_manage: bool}]`
  - `setNotify(array $actor, int $cid, bool $on): void` (tout type ; membre actif sinon 404)
  - `ChatService::requireWritable(array $membership): void` — lève l'erreur d'archive ; appelée par toutes les écritures de contenu.
  - Routes (`perm:chat.use`) : `GET /chat/channels` (`?archived=1`), `GET /chat/channels/new`, `POST /chat/channels`, `POST /chat/channels/{id}/join`, `POST /chat/channels/{id}/leave`, `GET /chat/channels/{id}/edit`, `POST /chat/channels/{id}`, `POST /chat/channels/{id}/archive`, `POST /chat/channels/{id}/unarchive`, `POST /chat/{id}/notify`.

- [ ] **Step 1 : tests en échec** (`ChannelServiceTest`)
  - `testCreateJoinSeeFullHistoryNoUnreadFlood` : chef de service crée « Chantier Pont » ; 3 messages ; un collaborateur rejoint → `history` contient les 3 messages, `unreadTotal` = 0, `notify` = 0 ; collaborateur ne peut pas créer (403).
  - `testManageRights` (Review Focus 3) : chef A crée ; chef B → `update`/`archive` 403 ; directeur et admin → autorisés sans être membres.
  - `testArchivedRefusesWritesButReadableAndJoinable` : archivé → `send`, `react`, `edit` (message récent), `uploadAttachment` → message d'archive ; un non-membre rejoint et lit l'historique ; liste par défaut sans le salon, `list(…, true)` avec.
  - `testNameUniqueAmongOpenChannels` : doublon refusé « Un salon porte déjà ce nom. » ; possible après archivage du premier.
  - `testChannelMessageNotifiesOnlyOptedIn` : 2 membres, l'un `setNotify(true)` → une seule notification `chat.message`.
  - `testPagesRender` : `/chat/channels` liste avec « Rejoindre » ; `POST …/join` → redirection vers `/chat/{id}` ; en-tête montre « Quitter le salon » et l'interrupteur « Alertes ».
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChannelServiceTest` → FAIL.
- [ ] **Step 3 :** implémenter (lire `reference/craft-floor.md` d'Impeccable avant les vues ; icônes `hash` et `building-2` ; écran « Salons » dans le cadre `.kb-chat`).
- [ ] **Step 4 :** suite complète, `cron:run`, smoke et régressions → verts.
- [ ] **Step 5 :** commit « Messagerie : salons ».

### Task 2 : salons de service et synchronisation

**Files :**
- Create : `tests/Integration/ChannelSyncTest.php`
- Modify : `app/Modules/Chat/ChannelService.php`, `app/Modules/Users/UserService.php` (après `create`, `update`, `setStatus`), `app/Modules/Organization/OrganizationService.php` (après `saveDepartment`), `app/Core/App.php` (injection), `bin/console` (`cron:run` → `syncDepartments()`, ajouté au compte rendu `chat_departments`), `database/migrations/0008_chat_channels.sql` reste inchangée (création initiale par la synchronisation au premier `cron:run` / premier appel)

**Interfaces :**
- Consumes : tâche 1.
- Produces : `ChannelService::syncDepartments(): array{created:int, added:int, removed:int}` — idempotente ; crée le salon des services actifs qui n'en ont pas ; renomme (évènement `renamed`, `actor_id` NULL) si le nom du service a changé ; archive/désarchive selon `is_active` ; ajoute les membres attendus (`member_added`, `actor_id` NULL, `joined_event_id` 0, positions = dernier évènement) et retire les autres (`member_left`, `actor_id` NULL) ; aucune notification, aucun audit. Les services appellent `syncDepartments()` après écriture (une dépendance `?ChannelService` optionnelle dans leurs constructeurs, passée par `App`).

- [ ] **Step 1 : tests en échec** (`ChannelSyncTest`)
  - `testServiceSalonCreatedWithMembers` : 2 services, 3 personnes → après `syncDepartments()`, 2 salons de service nommés comme les services, membres exacts, `notify` = 0.
  - `testTransferMovesBetweenSalonsImmediately` : modification du service d'une personne par `UserService::update` → retirée de l'ancien, ajoutée au nouveau, sans `cron:run`.
  - `testCronCatchesDirectDatabaseChange` : `UPDATE users SET department_id = …` puis `syncDepartments()` → membres corrigés ; second appel → `{created:0, added:0, removed:0}`.
  - `testDisabledUserRemovedAndServiceDeactivationArchives` : `setStatus(false)` → retiré ; service désactivé → salon archivé ; réactivé → désarchivé.
  - `testCannotLeaveOrManageServiceSalon` : `leave` → 422 ; `update`/`archive` par un admin → 422 « Le salon d'un service suit l'annuaire. »
  - `testRenamedServiceRenamesSalon` : `saveDepartment` avec un nouveau nom → titre du salon et évènement `renamed`.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChannelSyncTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** suite complète, `cron:run` (affiche `chat_departments`), smoke et régressions → verts.
- [ ] **Step 5 :** commit « Messagerie : salons de service tenus à jour d'après l'annuaire ».

### Task 3 : accusés de lecture

**Files :**
- Create : `tests/Integration/ChatReadsTest.php`
- Modify : `app/Modules/Chat/ChatRepository.php` (`reads(int $cid): array` → `[{user_id, name, last_read_event_id, entered_event_id}]` des membres actifs), `app/Modules/Chat/ChatController.php` (`updates` : `reads` si `open` et membre actif ; page : `reads` transmis à la vue), `app/Views/chat/index.php` + `_message.php` (mention sur mes messages), `public/assets/js/chat.js` (envoie `open=<cid>`, met à jour la mention ; liste des noms dans une fenêtre accessible), `public/assets/css/app.css`, `tests/e2e/smoke.js`

**Interfaces :**
- Produces : réponse `GET /api/chat/updates?since=…&open=<cid>` + `"reads": [{"user_id", "name", "last_read_event_id", "entered_event_id"}]` (autres membres actifs ; clé absente si `open` absent ou non-membre). Règle d'affichage (serveur et JS, même fonction) : lecteurs d'un de mes messages d'évènement `E` = membres ≠ moi avec `last_read_event_id ≥ E` et `entered_event_id < E` ; privé → « Vu » sous le dernier de mes messages ayant un lecteur ; groupe/salon → « Vu par N » sous mon dernier message seulement (N ≥ 1), bouton ouvrant la liste des noms (`role="dialog"` léger, Échap pour fermer).

- [ ] **Step 1 : tests en échec** (`ChatReadsTest`)
  - `testReadsOnlyForMembers` (Review Focus 4) : `open` = conversation d'autrui → pas de clé `reads` ; membre → clé présente.
  - `testLateJoinerNotCountedAsReader` (Review Focus 5) : salon, mon message, puis 2 membres lisent, puis un nouveau venu rejoint → page : « Vu par 2 ».
  - `testDirectSeenUnderLastReadMessage` : privé, 2 messages, l'autre lit le premier seulement → « Vu » sous le premier ; après lecture du second → sous le second.
  - `smoke.js` : A envoie dans la conversation privée, B l'ouvre → chez A « Vu » apparaît sans rechargement.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatReadsTest` → FAIL ; smoke → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** suite, smoke, régressions → verts.
- [ ] **Step 5 :** commit « Messagerie : accusés de lecture ».

### Task 4 : recherche

**Files :**
- Create : `app/Views/chat/search.php`, `tests/Integration/ChatSearchTest.php`
- Modify : `app/Modules/Chat/ChatRepository.php` (`search(int $userId, string $q, int $limit = 50): array`, `around(int $userId, int $cid, int $messageId): array`), `app/Modules/Chat/ChatController.php` (`search`, `show` accepte `?around=`), `routes/web.php` (`GET /chat/search` déclarée avant `/chat/{id}`), `app/Views/chat/_list.php` (champ de recherche, formulaire GET), `app/Views/chat/index.php` (message mis en évidence, « Afficher les messages suivants »), `public/assets/js/chat.js` (en mode `around`, pas d'ajout automatique en bas tant que la fin n'est pas chargée ; défilement vers le message), `public/assets/css/app.css`

**Interfaces :**
- Produces :
  - `search()` → `[{message_id, conversation_id, conversation_title, sender_name, created_at, excerpt_html, matched: 'text'|'file'}]` ; `excerpt_html` = ~120 caractères centrés sur la première occurrence, **échappé puis** occurrences entourées de `<mark>` ; requête < 2 caractères → `ValidationException(['q' => 'Tapez au moins 2 caractères.'])`, > 100 tronquée.
  - `around()` → 25 évènements avant et 25 après le message (mêmes filtres que `history`), plus `has_more_after: bool`.
  - Page `GET /chat/search?q=` : liste des résultats ; « Aucun message ne correspond à « … ». » ; liens `/chat/{cid}?around={message_id}#m{message_id}`.

- [ ] **Step 1 : tests en échec** (`ChatSearchTest`)
  - `testSearchRespectsVisibility` (Review Focus 1) : non-membre d'un groupe → 0 résultat ; membre ajouté après le message → 0 ; message supprimé → 0 ; membre arrivé après dans un salon → trouvé.
  - `testWildcardsAreLiteralAndFilenamesMatch` : recherche `50%` ne trouve pas « 500 » ; `_` littéral ; nom de fichier « Suivi béton.xlsx » trouvé par « béton » avec `matched = file`.
  - `testExcerptIsEscapedAndHighlighted` : message `<b>dalle</b> coulée` cherché « dalle » → `&lt;b&gt;<mark>dalle</mark>&lt;/b&gt;`.
  - `testAroundOpensOnMessage` : 80 messages, `around` du 10ᵉ → page contient le 10ᵉ avec la classe de mise en évidence et pas le 80ᵉ ; « Afficher les messages suivants » présent.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatSearchTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** suite, smoke, régressions → verts.
- [ ] **Step 5 :** commit « Messagerie : recherche ».

### Task 5 : démonstration, documentation et vérification

**Files :**
- Modify : `database/seeds/demo.php` (salon « Chantier Pont de Sotuba » créé par Moussa avec 2 membres et 2 messages ; salon « Matériel et engins » archivé ; salons de service par `syncDepartments()`), `tests/e2e/smoke.js` (rejoindre un salon et y lire un message antérieur ; recherche d'un texte envoyé pendant le test), `docs/ARCHITECTURE.md`, `docs/DATABASE.md` (migration 0008), `docs/SECURITY.md` (recherche, positions de lecture), `docs/ADMINISTRATION.md` (salons, salons de service, droits, alertes), `PLAN.md`

- [ ] **Step 1 :** rendu PC et iPhone 13 (liste avec salons, écran « Salons », salon archivé, recherche, accusés) ; `impeccable detect` sur le HTML rendu → aucun avertissement hors faux positifs connus (`cramped-padding` du cadre `.kb-chat`, `dark-glow` de l'ombre du menu).
- [ ] **Step 2 :** seed vérifié sur une base vierge (`DB_DATABASE=kibarou_seedcheck`), puis base supprimée.
- [ ] **Step 3 :** documentation.
- [ ] **Step 4 :** suite, `cron:run`, smoke, régressions → verts.
- [ ] **Step 5 :** commit « Lot 4c : démonstration, documentation et vérifications » ; push.
