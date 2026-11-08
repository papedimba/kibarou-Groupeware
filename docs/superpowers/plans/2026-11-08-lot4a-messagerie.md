# Lot 4a — Messagerie (l'essentiel) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** conversations privées et de groupe, messages texte, « temps réel » par interrogation adaptative sur un journal d'évènements, non-lus, notifications, bouton « Écrire » de l'annuaire.

**Architecture :** module `app/Modules/Chat/` (dépôt de lecture, service métier, contrôleurs pages + API) ; journal `chat_events` comme curseur unique ; page rendue par le serveur puis enrichie par `public/assets/js/chat.js` (transport isolé `poll`) ; réutilise `Notifier`, l'interrogation passive et la présence. Migration `0005`.

**Tech Stack :** PHP 8.2 natif, MySQL/MariaDB, JS natif (Fetch, localStorage), Bootstrap 5.3.3, PHPUnit 11, Playwright 1.63 (Chromium complet).

**Spec :** `docs/superpowers/specs/2026-11-08-messagerie-4a-design.md` (approuvée). Contexte : `PRODUCT.md`, ADR 0004.

## Global Constraints

- Confidentialité stricte : chaque page, appel API, envoi et marquage vérifie l'appartenance **active** ; un membre ne voit que les évènements d'id > `joined_event_id` ; aucun contenu de message dans `notifications`, `mail_queue`, `audit_logs`.
- Messages : 1 à 4 000 caractères non blancs ; texte brut échappé ; liens `http(s)://` seuls rendus cliquables (`rel="noopener noreferrer" target="_blank"`) ; 30 messages/min/personne au plus (429).
- Groupe : nom 1–80 caractères ; 2 à 50 autres membres à la création ; 51 membres au total au plus.
- Session : `GET /api/*` passifs ; `POST` d'envoi actif ; `POST …/read` actif seulement avec `active=1`.
- Interrogation : réglages `chat.poll` dans `config/app.php` (`active_ms` 3000, `idle_ms` 10000, `background_ms` 30000), transmis à la page en attributs `data-*` ; arrêt onglet caché.
- Aucun script ni style inline ; WCAG AA ; cibles ≥ 44 px ; textes en français.
- Fin de chaque tâche : `vendor/bin/phpunit` **et** `node tests/e2e/smoke.js` verts (et `php bin/console cron:run` sans erreur).

## Review Focus

1. **Membre ajouté tardivement** : ni l'API d'évènements ni « Messages précédents » ne lui livrent un message antérieur à son ajout → `ChatAccessTest::testLateMemberSeesNoEarlierHistory` (tâche 2).
2. **Membre parti qui réutilise un ancien onglet** : envoi, lecture et marquage → 404, rien n'est écrit → `ChatAccessTest::testFormerMemberIsLockedOut` (tâche 2).
3. **Deux personnes cliquent « Écrire » l'une vers l'autre en même temps** : une seule conversation privée (clé unique, doublon récupéré) → `ChatServiceTest::testDirectConversationIsUniqueEvenOnDuplicateInsert` (tâche 1).
4. **Message contenant du HTML ou un faux lien `javascript:`** : affiché tel quel, jamais interprété, page comme JSON → `ChatPagesTest::testMessageIsEscapedAndOnlyHttpLinksAreClickable` (tâche 4).
5. **Destinataire désactivé dans une conversation privée** : envoi refusé avec un message clair, l'historique reste lisible → `ChatServiceTest::testCannotSendToDisabledUser` (tâche 1).

---

### Task 1 : schéma et service de base (conversations privées, envoi)

**Files :**
- Create : `database/migrations/0005_chat.sql`, `app/Modules/Chat/ChatService.php`, `app/Modules/Chat/ChatRepository.php`, `tests/Integration/ChatServiceTest.php`
- Modify : `app/Core/App.php` (`chat(): ChatService`), `config/app.php` (`chat.poll`)

**Migration 0005 :** tables `conversations`, `conversation_members`, `messages`, `chat_events` exactement selon la spec §5.1 ; index `chat_events (conversation_id, id)`, `conversation_members (user_id, left_at)` ; permission `chat.use` (module `chat`, « Utiliser la messagerie ») donnée à admin, directeur, chef_service, collaborateur ; `UPDATE modules SET is_enabled = 1 WHERE slug = 'chat'`.

**Interfaces :**
- Produces :
  - `ChatService::__construct(Database $db, ChatRepository $repo, Rbac $rbac, AuditLogger $audit)` (le `Notifier` est ajouté à la tâche 3)
  - `directWith(array $actor, int $otherId): int` — id de la conversation privée (crée la conversation, les 2 membres et l'évènement `created` si absente ; doublon de `direct_key` → relit l'existante) ; soi-même ou utilisateur inexistant/sans `chat.use` → `HttpException(404)`
  - `send(array $actor, int $conversationId, string $body): array{event_id:int, message_id:int}` — vérifie l'appartenance active (`requireMember`), longueur, limite 30/min (compte des `messages` de l'expéditeur sur 60 s), destinataire actif pour une conversation privée (sinon `ValidationException(['body' => 'Cette personne ne fait plus partie de l\'intranet : elle ne peut plus recevoir de messages.'])`) ; transaction : message + évènement `message` + `last_event_at`
  - `requireMember(int $userId, int $conversationId): array` (ligne d'appartenance active, sinon `HttpException(404)`)
  - `ChatRepository::__construct(Database $db)`

- [ ] **Step 1 : tests en échec** (`ChatServiceTest`)
  - `testDirectWithCreatesOnceAndReturnsSame` : deux appels A→B et un appel B→A → même id ; 2 membres ; 1 évènement `created`.
  - `testDirectConversationIsUniqueEvenOnDuplicateInsert` : conversation A–B insérée directement en base, puis `directWith(A, B)` → renvoie cet id (pas d'exception, pas de seconde ligne).
  - `testCannotWriteToSelfOrUnknown` : `directWith(A, A)` et `directWith(A, 999999)` → 404.
  - `testSendCreatesMessageAndEvent` : `send` → 1 message, 1 évènement `message` lié, `last_event_at` mis à jour.
  - `testSendValidation` : vide, espaces seuls, 4 001 caractères → `ValidationException` ; 4 000 → OK.
  - `testRateLimit` : 30 envois OK, le 31e → `HttpException` 429.
  - `testCannotSendToDisabledUser` : B désactivé → `ValidationException` avec le message de la spec ; l'historique existant reste en base.
  - `testNonMemberCannotSend` : C → 404.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatServiceTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Messagerie : schéma et conversations privées ».

### Task 2 : groupes et contrôle d'accès dans le temps

**Files :**
- Modify : `app/Modules/Chat/ChatService.php`, `app/Modules/Chat/ChatRepository.php`
- Create : `tests/Integration/ChatAccessTest.php`

**Interfaces :**
- Produces :
  - `ChatService::createGroup(array $actor, string $name, list<int> $memberIds): int` (2 à 50 autres membres actifs avec `chat.use`, nom 1–80 ; évènements `created` puis un `member_added` par membre ; `joined_event_id` de chacun = id de l'évènement `created` ; audit `chat.group_created` sans contenu)
  - `addMembers(array $actor, int $cid, list<int> $userIds): void` (groupe seulement ; plafond 51 ; un membre parti est réactivé avec un **nouveau** `joined_event_id` = id de son évènement `member_added` ; audit `chat.member_added`)
  - `leave(array $actor, int $cid): void` (groupe seulement ; évènement `member_left` puis `left_at` ; audit `chat.member_left`)
  - `rename(array $actor, int $cid, string $name): void` (évènement `renamed`)
  - `ChatRepository::events(int $userId, int $since, int $limit = 200): list<array>` — évènements des conversations où l'utilisateur est membre actif, `id > max(since, joined_event_id)`, avec `sender_name`, `body`, `conversation_id`, `type`, `actor_name`, `target_name` ; ordre croissant
  - `ChatRepository::history(int $userId, int $cid, ?int $beforeEventId, int $limit = 50): list<array>` — même filtre, du plus ancien au plus récent, avant `beforeEventId`
  - `AuditLabels` : libellés « Groupe de messagerie créé », « Membre ajouté à un groupe », « Groupe de messagerie quitté ».

- [ ] **Step 1 : tests en échec** (`ChatAccessTest`)
  - `testCreateGroupRules` : nom vide ou 81 caractères, 1 seul autre membre, 51 autres membres → `ValidationException` ; cas valide → membres + évènements.
  - `testLateMemberSeesNoEarlierHistory` : A crée un groupe avec B, envoie « avant » ; A ajoute C ; A envoie « après » → `events(C, 0)` et `history(C, cid, null)` contiennent « après », pas « avant » ; l'évènement « A a ajouté C » est visible de C.
  - `testFormerMemberIsLockedOut` : B quitte → `send(B)`, `requireMember(B)` → 404 ; `events(B, 0)` ne contient plus rien de ce groupe.
  - `testReAddedMemberRestartsFromReAdd` : B quitte, message « pendant », B ré-ajouté → B ne voit pas « pendant ».
  - `testMemberCapAndDirectHasNoGroupActions` : ajout au-delà de 51 → `ValidationException` ; `addMembers`/`leave`/`rename` sur une conversation privée → `ValidationException`.
  - `testAuditHasNoMessageContent` : après création de groupe, ajout, envoi « secret-123 » → `audit_logs.details` ne contient pas « secret-123 ».
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatAccessTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Messagerie : groupes et accès limité à la période d'appartenance ».

### Task 3 : non-lus, marquage lu et notifications

**Files :**
- Modify : `app/Modules/Chat/ChatService.php` (injection `Notifier`), `app/Modules/Chat/ChatRepository.php`, `app/Modules/Notifications/NotificationTypes.php` (`chat.message` : « Nouveau message », digest, navigateur oui), `app/Core/App.php`
- Create : `tests/Integration/ChatNotificationsTest.php`

**Interfaces :**
- Produces :
  - `ChatService::markRead(array $actor, int $cid, int $eventId): void` — `last_read_event_id = GREATEST(courant, min(eventId, dernier id visible))`, `last_read_at = maintenant` ; marque lues les notifications `chat.message` non lues dont `url = '/chat/{cid}'` de cet utilisateur
  - `ChatRepository::conversationsFor(int $userId): list<array>` — conversations actives triées par `last_event_at` desc : `id`, `type`, `title` (nom du groupe, ou nom de l'autre personne), `other_user` (privée : id, prénom, nom, `avatar_path`), `last_body` (extrait 80 caractères), `last_event_at`, `unread` (évènements `message` d'un autre expéditeur, id > `last_read_event_id` et > `joined_event_id`)
  - `ChatRepository::unreadTotal(int $userId): int`
  - Dans `send` : pour chaque autre membre actif, notifier `chat.message` (titres de la spec §5.5, lien `/chat/{cid}`, sans extrait) **sauf** si `last_read_at` < 2 min ou s'il existe déjà une notification `chat.message` non lue pour ce lien.

- [ ] **Step 1 : tests en échec** (`ChatNotificationsTest`)
  - `testUnreadCountsAndMarkRead` : B envoie 3 messages à A → `conversationsFor(A)` `unread` = 3, `unreadTotal(A)` = 3 ; ses propres messages ne comptent pas ; `markRead` au dernier évènement → 0 ; `markRead` avec un id inférieur ne fait pas remonter le compteur.
  - `testOneNotificationPerConversationUntilRead` : 3 messages de B → 1 notification pour A, titre « Nouveau message de {Prénom Nom} », `url` `/chat/{cid}` ; `markRead` → la notification est lue ; message suivant → nouvelle notification **seulement si** `last_read_at` > 2 min (simulé en base).
  - `testGroupNotificationTitleHasGroupName` : « Nouveau message de {Prénom Nom} dans {groupe} ».
  - `testNoMessageContentInNotificationsOrMail` : message « secret-456 » → absent de `notifications` (titre et précision) et de `mail_queue` (immédiat forcé par préférence).
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatNotificationsTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Messagerie : non-lus et notifications ».

### Task 4 : pages (liste, conversation, nouvelle conversation, annuaire)

**Files :**
- Create : `app/Modules/Chat/ChatController.php`, `app/Views/chat/index.php`, `app/Views/chat/_list.php`, `app/Views/chat/_message.php`, `app/Views/chat/new.php`, `tests/Integration/ChatPagesTest.php`
- Modify : `routes/web.php`, `app/Views/layouts/app.php` (entrée « Messagerie » + pastille `data-chat-badge`), `app/Core/Controller.php` (partage `chatUnread`), `app/Views/directory/show.php` (« Écrire » → `/chat/with/{id}` selon `chat.use`), `app/Core/helpers.php` (`linkify(string $text): string` — échappe puis rend cliquables les seuls `https?://…`), `public/assets/css/app.css`

**Interfaces :**
- Consumes : tâches 1–3.
- Produces : routes (`auth` + `perm:chat.use`) `GET /chat`, `GET /chat/{id}` (marque lu jusqu'au dernier évènement visible), `GET /chat/new`, `POST /chat/new` (1 personne → privée ; ≥ 2 + nom → groupe), `GET /chat/with/{userId}`, `POST /chat/{id}` (envoi sans JS, redirection), `POST /chat/{id}/members`, `POST /chat/{id}/leave`, `POST /chat/{id}/rename`. Vue conversation : `<main>` porte `data-chat-conversation="{id}"`, `data-last-event="{id}"`, `data-poll-active|idle|background` (depuis `chat.poll`) ; chaque message `data-event-id` ; formulaire `data-chat-form`.

- [ ] **Step 1 : tests en échec** (`ChatPagesTest`)
  - `testListAndEmptyState` : sans conversation → « Aucune conversation pour l'instant. » et bouton « Nouvelle conversation ».
  - `testWriteFromDirectoryOpensConversation` : la fiche annuaire contient `href="/chat/with/{id}"` ; `GET /chat/with/{id}` → redirection vers `/chat/{cid}`.
  - `testSendWithoutJavascript` : `POST /chat/{cid}` `body=Bonjour` → redirection, le message apparaît dans `GET /chat/{cid}`.
  - `testMessageIsEscapedAndOnlyHttpLinksAreClickable` : message `<script>alert(1)</script> https://kibarou.test javascript:alert(1)` → page contient `&lt;script&gt;`, un `<a href="https://kibarou.test"` avec `rel="noopener noreferrer"`, et aucun `href="javascript:`.
  - `testNonMemberGets404` ; `testCreateGroupFromForm` (nom + 2 personnes → groupe affiché avec le nom) ; `testMenuShowsUnreadTotal` (2 non-lus → la pastille du menu affiche 2).
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatPagesTest` → FAIL.
- [ ] **Step 3 :** implémenter (lecture préalable de `reference/craft-floor.md` d'Impeccable ; PC : deux colonnes ; téléphone : liste puis conversation plein écran avec bouton retour ; séparateurs « Aujourd'hui » / « Hier » / date ; zone de saisie en bas).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Messagerie : écrans PC et téléphone ».

### Task 5 : API, interrogation adaptative et client JavaScript

**Files :**
- Create : `public/assets/js/chat.js`, `tests/Integration/ChatApiTest.php`
- Modify : `app/Modules/Chat/ChatController.php` (actions API), `routes/web.php`, `app/Core/App.php` (règle de session : passif si `GET /api/*`, ou `POST /api/chat/conversations/{id}/read` sans `active=1`), `app/Views/chat/index.php` (script), `app/Modules/Notifications/NotificationsController.php` (`chat_unread` dans la réponse de `/api/notifications`), `public/assets/js/app.js` (met à jour `data-chat-badge`), `tests/e2e/smoke.js`

**Interfaces :**
- Produces : `GET /api/chat/updates?since=<int>` → `{"events": [{"id","conversation_id","type","sender_id","sender_name","body","actor_name","target_name","created_at"}], "unread": {"<cid>": int}, "total_unread": int}` ; `GET /api/chat/conversations/{id}/history?before=<event id>` → `{"events": [...]}` ; `POST /api/chat/conversations/{id}/messages` (JSON `{"body"}`, en-tête `X-CSRF-Token`) → `{"event": {...}}` ou 422/429 JSON ; `POST /api/chat/conversations/{id}/read` (`event_id`, `active`) → `{"ok": true}`.
- `chat.js` : objet `transport` avec `poll(since)` (seul transport en 4a) ; rythme selon les attributs `data-poll-*` et l'interaction (touche, clic, toucher, défilement) ; ajoute les messages via `textContent` + liens construits par `document.createElement('a')` pour les seuls `https?://` ; brouillon dans `localStorage` (`kb-draft-{cid}`, try/catch) ; échec d'envoi → bulle « Non envoyé — Réessayer » sans perte du texte ; Entrée envoie (PC), Maj+Entrée va à la ligne.

- [ ] **Step 1 : tests en échec** (`ChatApiTest`)
  - `testUpdatesReturnsOnlyMyEventsAfterSince` : événements de mes conversations seulement, `id > since`, `unread` par conversation et `total_unread`.
  - `testSendJsonRequiresMembershipAndCsrf` : sans jeton → 419 ; non-membre → 404 ; valide → 200 avec `event.id` ; trop long → 422 JSON.
  - `testHistoryPaginates` : 60 messages → `history` renvoie 50, puis `before` → les 10 plus anciens.
  - `testPollDoesNotExtendSessionButSendDoes` : `last_activity_at` ancien de 10 min → `GET /api/chat/updates` ne le change pas ; `POST …/messages` le met à jour.
  - `testReadIsPassiveUnlessActive` : `POST …/read` sans `active` → `last_activity_at` inchangé ; avec `active=1` → mis à jour.
  - `testNotificationsPollIncludesChatUnread` : `/api/notifications` contient `chat_unread`.
  - `smoke.js` : deux contextes Chromium (`pape@kibarou.test` et un compte de démo `KB_DEMO_EMAIL`/`KB_DEMO_PASSWORD`, test ignoré avec un message si absents) : A ouvre la conversation privée avec B ; B envoie « Smoke {horodatage} » via l'interface ; le texte apparaît chez A en moins de 5 s sans rechargement ; un texte tapé non envoyé est retrouvé après rechargement (brouillon).
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatApiTest` → FAIL ; smoke → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Messagerie : temps réel par interrogation adaptative ».

### Task 6 : démonstration, documentation et vérification

**Files :**
- Modify : `database/seeds/demo.php` (une conversation privée Moussa ↔ Seydou et un groupe « Chantier Résidence Les Palmiers »), `docs/ARCHITECTURE.md` (§5 messagerie : réalisé), `docs/DATABASE.md`, `docs/SECURITY.md`, `docs/ADMINISTRATION.md`, `docs/DEPLOYMENT.md` (réglages `chat.poll`, mesurer la charge chez Likuid), `PLAN.md`

- [ ] **Step 1 :** rendu PC et iPhone 13 de `/chat`, d'une conversation, de `/chat/new` ; corrections selon `craft-floor.md`.
- [ ] **Step 2 :** `impeccable detect --json` sur le HTML rendu → aucun avertissement hors faux positifs connus.
- [ ] **Step 3 :** seed vérifié sur une base vierge ; documentation.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; `php bin/console cron:run` OK ; smoke → « Smoke UI OK ».
- [ ] **Step 5 :** commit « Lot 4a : démonstration, documentation et vérifications » ; push.
