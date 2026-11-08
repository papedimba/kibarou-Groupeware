# Lot 4b — Messagerie (le confort) : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** pièces jointes (photos réduites, .docx, .xlsx, PDF), réponse citée, réactions, modification et suppression sous 15 minutes, envoi sans doublon, dans la messagerie du lot 4a.

**Architecture :** nouveau `ChatAttachmentService` (contrôle, réencodage, stockage privé, nettoyage) ; `ChatService` étendu (envoi avec `client_id` / pièces jointes / réponse ; `edit`, `delete`, `react`) ; trois nouveaux types d'évènements dans `chat_events`, diffusés par l'interrogation existante ; `ChatRepository::hydrate()` enrichit les évènements (pièces jointes, réactions, extrait cité) pour la page comme pour l'API ; `chat.js` gère envoi en deux temps, réduction des photos, menu d'actions. Migration `0007`.

**Tech Stack :** PHP 8.2 natif (GD + WebP, fileinfo, exif, zip), MySQL/MariaDB, JS natif (Fetch, XMLHttpRequest pour la progression, canvas), Bootstrap 5.3.3, Lucide, PHPUnit 11, Playwright (Chromium complet).

**Spec :** `docs/superpowers/specs/2026-11-08-messagerie-4b-design.md` (approuvée). Base : conception et code du lot 4a.

## Global Constraints

- Formats : photos JPEG/PNG/WebP → réencodées en WebP (grand côté ≤ 2 000 px, vignette 480 px, EXIF/GPS supprimés, orientation corrigée, décodage ≤ 24 mégapixels) ; `.docx`, `.xlsx` (contrôle `ZipArchive` : `[Content_Types].xml` Word/Excel, refus si `vbaProject.bin` ; sans extension zip → refus) ; `.pdf`. Anciens `.doc`/`.xls`, `.docm`/`.xlsm` et tout le reste refusés. Type vérifié par `finfo`, jamais par le navigateur.
- Messages d'erreur exacts : « Ce type de fichier n'est pas accepté. Formats possibles : photos, Word (.docx), Excel (.xlsx), PDF. » ; « Les anciens formats .doc et .xls ne sont pas acceptés. Enregistrez le fichier au format .docx ou .xlsx avant de l'envoyer. » ; « Cette photo est trop lourde (6 Mo au maximum). Prenez-la en qualité réduite ou envoyez-la depuis un autre appareil. »
- 6 Mo par fichier, 10 pièces jointes par message, 30 fichiers/min/personne (429) ; texte facultatif s'il y a ≥ 1 pièce jointe, sinon 1 à 4 000 caractères.
- Modifier (texte seul) / supprimer : auteur seulement, ≤ 15 minutes (900 s) après `created_at`, vérifié côté serveur.
- Réactions fixes : 👍 ✅ 👀 🙏 ❤️ 😂 ; bascule ; aucune notification.
- Confidentialité 4a inchangée ; en plus : fichier servi seulement à un membre actif qui voit le message (évènement d'origine > `joined_event_id`) non supprimé ; `message_edited` / `message_deleted` / `reaction` livrés seulement si l'évènement `message` d'origine est visible ; extrait cité d'un message antérieur → « Message antérieur à votre arrivée ». Aucun contenu ni nom de fichier dans `notifications`, `mail_queue`, `audit_logs`.
- Seuls les nouveaux messages et les évènements de membre mettent à jour `last_event_at` ; modification/suppression/réaction ne changent ni l'ordre ni les non-lus.
- Stockage : `storage/private/chat/AAAA/MM/<32 hex>.webp|.bin` (+ `-t.webp`) ; orphelins (non rattachés) > 24 h effacés par `cron:run`.
- Aucun script ni style inline ; WCAG AA ; cibles ≥ 44 px ; textes en français.
- Fin de chaque tâche : `vendor/bin/phpunit`, `php bin/console cron:run` et `node tests/e2e/smoke.js` verts.

## Review Focus

1. **Pièce jointe détournée** : un `attachment_id` d'un autre expéditeur, d'une autre conversation ou déjà rattaché est refusé à l'envoi → `ChatAttachmentsTest::testForeignAttachmentRejected` (tâche 2).
2. **Fichier lu hors de son cercle** : non-membre, membre parti, membre arrivé après l'envoi, message supprimé → 404 sur fichier et vignette → `ChatAttachmentsTest::testDownloadRequiresVisibleMessage` (tâche 2).
3. **Membre arrivé tard** : aucune modification, suppression, réaction ni extrait d'un message antérieur ne lui parvient → `ChatActionsTest::testLateMemberGetsNothingAboutEarlierMessages` (tâche 3).
4. **Faux fichier** : script renommé `.jpg`, zip quelconque ou `.docm` renommé `.docx`, ancien `.doc` → refusés avec le bon message ; image réencodée sans EXIF → `ChatAttachmentServiceTest` (tâche 1).
5. **« Réessayer » après une réponse perdue** : même `client_id` deux fois → un seul message, réponse identique → `ChatAttachmentsTest::testSameClientIdCreatesOneMessage` (tâche 2).

---

### Task 1 : stockage et contrôle des pièces jointes

**Files :**
- Create : `database/migrations/0007_chat_comfort.sql`, `app/Modules/Chat/ChatAttachmentService.php`, `tests/Integration/ChatAttachmentServiceTest.php`, `tests/fixtures/chat/` (petits fichiers de test générés par le test lui-même si possible)
- Modify : `app/Core/App.php` (`chatAttachments()`), `bin/console` (`cron:run` appelle `purgeOrphans()` et l'ajoute au compte rendu), `public/.user.ini` (commentaire : pièces jointes 6 Mo)

**Migration 0007 :** exactement la spec §5.1 (colonnes `messages.client_id` CHAR(32) NULL + unique `(sender_id, client_id)`, `reply_to_id` FK SET NULL, `edited_at`, `deleted_at` ; tables `chat_attachments`, `chat_reactions` ; ENUM `chat_events.type` + `message_edited`, `message_deleted`, `reaction`) ; index `chat_attachments (message_id)` et `(created_at)`.

**Interfaces :**
- Produces :
  - `ChatAttachmentService::__construct(Database $db, string $privateDir)` ; `App::chatAttachments(): ChatAttachmentService`
  - `store(int $uploaderId, int $conversationId, array $file): array` — contrôle puis enregistre ; renvoie la ligne `chat_attachments` insérée (`message_id` NULL) ; `ValidationException(['file' => …])` avec les messages exacts des Global Constraints
  - `absolutePath(string $relative): ?string` — refuse tout chemin hors du motif `#^chat/\d{4}/\d{2}/[a-f0-9]{32}(-t)?\.(webp|bin)$#`
  - `deleteFiles(array $rows): void` — efface fichier et vignette de chaque ligne (sans erreur si absent)
  - `purgeOrphans(): int` — lignes `message_id IS NULL` créées il y a plus de 24 h : fichiers puis lignes ; renvoie le nombre
  - `ChatAttachmentService::MAX_PER_MESSAGE = 10`

- [ ] **Step 1 : tests en échec** (`ChatAttachmentServiceTest`, images générées avec GD, docx/xlsx construits avec `ZipArchive` dans le test)
  - `testPhotoIsReencodedResizedAndStripped` : JPEG 3000×1500 avec EXIF → ligne `kind=image`, `mime=image/webp`, `width=2000`, `height=1000`, vignette 480 de large présente, fichier sans segment EXIF.
  - `testDocxAndXlsxAndPdfAccepted` : `kind=file`, nom d'origine assaini conservé, fichier stocké en `.bin` identique à l'original.
  - `testRejectsFakesWithExactMessages` : PHP renommé `.jpg` et zip quelconque renommé `.docx` → message « Ce type de fichier… » ; docx contenant `word/vbaProject.bin` → idem ; fichier OLE renommé `.doc` → message « Les anciens formats… ».
  - `testTooHeavyPhotoMessage` : `error = UPLOAD_ERR_INI_SIZE` sur un `.jpg` → message « Cette photo est trop lourde… ».
  - `testPurgeOrphansDeletesOldUnattachedOnly` : orphelin de 25 h effacé (ligne + fichiers), orphelin de 1 h et pièce rattachée conservés.
  - `testAbsolutePathRejectsTraversal` : `../../.env`, `avatars/1-…webp` → `null`.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatAttachmentServiceTest` → FAIL (classe absente).
- [ ] **Step 3 :** migrer (`php bin/console migrate`), implémenter. Réutiliser la logique de `AvatarService::decode/orient` (copier les contrôles, pas d'héritage forcé) ; nom assaini : `basename`, caractères de contrôle et `/\\:*?"<>|` retirés, 160 caractères au plus.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; `php bin/console cron:run` affiche le compte des orphelins.
- [ ] **Step 5 :** commit « Messagerie : stockage et contrôle des pièces jointes ».

### Task 2 : envoi avec pièces jointes, réponse et identifiant client ; téléchargement

**Files :**
- Create : `tests/Integration/ChatAttachmentsTest.php`
- Modify : `app/Modules/Chat/ChatService.php`, `app/Modules/Chat/ChatRepository.php`, `app/Modules/Chat/ChatController.php`, `routes/web.php`

**Interfaces :**
- Consumes : tâche 1.
- Produces :
  - `ChatService::send(array $actor, int $conversationId, string $body, ?string $clientId = null, array $attachmentIds = [], ?int $replyToId = null): array{event_id:int, message_id:int}` — `client_id` (32 hex, sinon 422) déjà connu pour cet expéditeur → renvoie l'existant sans rien créer ni notifier ; pièces jointes : toutes à cet expéditeur, cette conversation, `message_id` NULL, au plus 10, sinon `ValidationException(['attachments' => 'Une pièce jointe est introuvable ou déjà envoyée.'])` ; rattachement dans la transaction du message ; `reply_to_id` : message de la même conversation visible par l'expéditeur, sinon 422 ; corps vide accepté s'il y a ≥ 1 pièce jointe. Les appels existants (sans les nouveaux arguments) gardent leur comportement.
  - `ChatService::uploadAttachment(array $actor, int $conversationId, array $file): array` — membre actif (404), limite 30/min (429, « Trop de fichiers en peu de temps. Patientez une minute. »), puis `chatAttachments()->store()`.
  - `ChatService::attachmentFor(int $userId, int $attachmentId): array` — ligne si membre actif, message rattaché, visible (évènement d'origine > `joined_event_id`) et non supprimé ; sinon `HttpException(404)` (l'expéditeur voit aussi ses pièces non rattachées, pour l'aperçu avant envoi).
  - `ChatRepository::hydrate(int $userId, array $events): array` — ajoute à chaque évènement `message` : `client_id`, `edited_at`, `deleted` (bool), `attachments` (`[{id, kind, name, size, width, height}]`, vide si supprimé), `reactions` (`[{emoji, count, mine, names}]`), `reply_to` (`{id, sender_name, excerpt}` | `{id, hidden:true}` | `{id, deleted:true}` | null) ; extrait = 80 premiers caractères, sinon « Photo » / « Fichier : nom ». Une requête par type de donnée pour tout le lot d'évènements (pas une par message). Appelé par `events()`, `history()` côté contrôleur et par la page.
  - Routes (groupe `perm:chat.use`) : `POST /api/chat/conversations/{id}/attachments` → `{"attachment": {id, kind, name, size, url, thumb_url}}` ; `GET /chat/attachments/{id}` ; `GET /chat/attachments/{id}/thumb`. `POST …/messages` accepte `client_id`, `attachment_ids[]`, `reply_to_id`.
  - `ChatController::event()` expose les champs de `hydrate` ; `url` / `thumb_url` construits avec `url('/chat/attachments/' . id)`.
  - Téléchargement : photo `image/webp` en ligne ; autres `application/octet-stream` + `Content-Disposition: attachment; filename="<ascii>"; filename*=UTF-8''<encodé>` ; toujours `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=86400`.

- [ ] **Step 1 : tests en échec** (`ChatAttachmentsTest`, via `postFiles()` de `IntegrationTestCase`)
  - `testUploadThenSendAttachesAndPayloadListsFiles` : envoi d'une photo puis message vide avec `attachment_ids` → 200 ; `/api/chat/updates` chez l'autre : `attachments[0].kind = 'image'`, `thumb_url` ; GET de la vignette par l'autre → 200 `image/webp`.
  - `testForeignAttachmentRejected` (Review Focus 1) : pièce d'un autre utilisateur ; d'une autre conversation ; déjà rattachée → 422 `errors.attachments`, aucun message créé.
  - `testDownloadRequiresVisibleMessage` (Review Focus 2) : non-membre, membre parti, membre ajouté après l'envoi → 404 sur fichier et vignette ; docx → `Content-Disposition: attachment` et `nosniff`.
  - `testSameClientIdCreatesOneMessage` (Review Focus 5) : deux `POST …/messages` avec le même `client_id` → même `event.id`, 1 ligne `messages`, 1 notification.
  - `testReplyExcerptAndHiddenForLateMember` : B répond à un message de A → `reply_to.excerpt` ; message cité antérieur à l'arrivée de D → chez D `reply_to.hidden = true`, sans extrait.
  - `testUploadRateLimit` : 31ᵉ fichier en une minute → 429.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatAttachmentsTest` → FAIL.
- [ ] **Step 3 :** implémenter.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS (les tests 4a restent verts).
- [ ] **Step 5 :** commit « Messagerie : pièces jointes, réponses et envoi sans doublon ».

### Task 3 : modifier, supprimer, réagir

**Files :**
- Create : `tests/Integration/ChatActionsTest.php`
- Modify : `app/Modules/Chat/ChatService.php`, `app/Modules/Chat/ChatRepository.php` (filtre de visibilité des nouveaux types dans `events()`/`history()` ; extrait de liste), `app/Modules/Chat/ChatController.php`, `routes/web.php`, `app/Modules/Admin/AuditLabels.php` (`chat.message_deleted` → « Message de messagerie supprimé »)

**Interfaces :**
- Consumes : tâches 1–2.
- Produces :
  - `ChatService::EDIT_WINDOW_SECONDS = 900` ; `ChatService::REACTIONS = ['👍', '✅', '👀', '🙏', '❤️', '😂']`
  - `edit(array $actor, int $messageId, string $body): int` — auteur, membre actif, non supprimé, ≤ 900 s, corps 1–4 000 (même si le message a des pièces jointes, un corps vide est refusé : « Le message est vide. ») ; sinon 404 (pas membre / introuvable) ou 422 (`['body' => 'Ce message ne peut plus être modifié.']` après le délai ou s'il n'est pas le vôtre) ; met `body`, `edited_at` ; évènement `message_edited` ; renvoie son id.
  - `delete(array $actor, int $messageId): int` — mêmes contrôles (« Ce message ne peut plus être supprimé. ») ; `body = ''`, `deleted_at` ; pièces jointes : `deleteFiles()` puis suppression des lignes ; réactions supprimées ; évènement `message_deleted` ; audit `chat.message_deleted` avec seulement `conversation_id` ; renvoie l'id d'évènement.
  - `react(array $actor, int $messageId, string $emoji): int` — membre actif, message visible et non supprimé, emoji dans `REACTIONS` (sinon 422) ; bascule ; évènement `reaction`.
  - `ChatService::event()` reçoit `bool $touch = true` ; les trois nouveaux types passent `false` (pas de `last_event_at`).
  - Les évènements `message_edited` / `message_deleted` / `reaction` portent `message_id` et, dans l'API, `message` = l'état hydraté du message cible ; ils ne comptent pas comme non-lus (`UNREAD` ne compte que `type = 'message'`, inchangé).
  - Routes : `POST /api/chat/messages/{id}/edit` (`body`), `POST /api/chat/messages/{id}/delete`, `POST /api/chat/messages/{id}/reactions` (`emoji`) → `{"event": {...}}`.
  - Extrait de liste (`conversationsFor.last_body`) : dernier message ; « Message supprimé » s'il l'est ; sinon texte, ou « Photo » / « Fichier : nom ».

- [ ] **Step 1 : tests en échec** (`ChatActionsTest` ; le temps est simulé en reculant `messages.created_at`)
  - `testEditWithinWindowThenRefusedAfter` : modification à 14 min → 200, `edited_at` renseigné, évènement `message_edited` reçu par l'autre avec le nouveau texte ; à 15 min 1 s → 422 ; message d'un autre → 422.
  - `testDeleteRemovesFilesAndContent` : message avec photo supprimé → fichiers absents du disque, lignes `chat_attachments` et `chat_reactions` supprimées, `body` vide, l'autre reçoit `message_deleted` ; GET de l'ancienne vignette → 404 ; audit sans contenu.
  - `testReactionToggleAndNoNotification` : 👍 deux fois → présent puis absent ; `🔥` → 422 ; aucune ligne `notifications` créée.
  - `testActionsDoNotReorderListOrCountUnread` : réaction et modification sur un vieux message → `last_event_at` inchangé, `total_unread` inchangé.
  - `testLateMemberGetsNothingAboutEarlierMessages` (Review Focus 3) : message M avant l'arrivée de D, puis modification, réaction (et suppression d'un second message antérieur) → `/api/chat/updates` et `history` de D ne contiennent aucun de ces évènements.
  - `testListExcerptForDeletedAndAttachmentOnly` : dernier message photo seule → « Photo » ; supprimé → « Message supprimé ».
- [ ] **Step 2 :** `vendor/bin/phpunit --filter ChatActionsTest` → FAIL.
- [ ] **Step 3 :** implémenter (filtre de visibilité : jointure sur l'évènement `message` d'origine du même `message_id`, exigé > `joined_event_id`).
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Messagerie : modifier, supprimer et réagir ».

### Task 4 : affichage serveur et espace disque

**Files :**
- Modify : `app/Views/chat/_message.php`, `app/Views/chat/index.php`, `app/Modules/Chat/ChatController.php` (`page()` passe par `hydrate`), `public/assets/css/app.css`, `app/Modules/Admin/AdminController.php` + `app/Views/admin/dashboard.php` (taille des pièces jointes, alerte au-delà de `chat.storage_warn_mb`), `config/app.php` (`'chat' => [..., 'storage_warn_mb' => (int) Env::get('CHAT_STORAGE_WARN_MB', 2000)]`), `tests/Integration/ChatPagesTest.php`, `tests/Integration/AdminTest.php` (ou le test du tableau de bord existant)

**Interfaces :**
- Consumes : `hydrate()` (tâche 2), état des messages (tâche 3).
- Produces (attributs utilisés par la tâche 5) : chaque `li.kb-msg` porte `data-event-id`, `data-message-id`, `data-mine`, `data-created` (ISO UTC), `data-deleted` ; bloc `.kb-msg-reply[data-reply-to]`, grille `.kb-msg-photos` (liens vers l'image, `<img>` vignette, `alt="Photo envoyée par …"`, « +N » au-delà de 4), cartes `.kb-msg-file` (icône Lucide `file-text` / `file-spreadsheet` / `file`, nom, taille lisible, lien « Télécharger »), `.kb-msg-edited` (« modifié »), `.kb-msg-reactions` (un `<button data-react="👍" aria-pressed>` par emoji présent, compteur, `title` et texte masqué avec les noms), bouton `.kb-msg-actions-btn` (« Actions », visible au survol et au focus) ; « Message supprimé » en italique à la place du contenu. Helper `format_bytes(int $bytes): string` dans `helpers.php` (« 240 Ko », « 1,2 Mo »).

- [ ] **Step 1 : tests en échec**
  - `ChatPagesTest::testMessageWithPhotoFileReplyReactionRendered` : page de la conversation contient la vignette (`/chat/attachments/{id}/thumb`), la carte du docx avec sa taille, l'extrait cité, le compteur 👍 et « modifié ».
  - `ChatPagesTest::testDeletedMessageShowsPlaceholderOnly` : « Message supprimé », aucun ancien texte.
  - test du tableau de bord : la taille totale des pièces jointes apparaît ; au-delà du seuil (configuré à 0 dans le test), l'alerte s'affiche.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter 'ChatPagesTest|Dashboard'` → FAIL.
- [ ] **Step 3 :** implémenter (lire `reference/craft-floor.md` d'Impeccable avant) ; rendu PC et iPhone 13 vérifié par capture.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK.
- [ ] **Step 5 :** commit « Messagerie : affichage des pièces jointes, réponses et réactions ».

### Task 5 : client JavaScript (joindre, réduire, actions)

**Files :**
- Create : `public/assets/js/chat-attachments.js` (sélection, réduction, envoi avec progression, aperçu avant envoi, visionneuse)
- Modify : `public/assets/js/chat.js` (rendu enrichi des évènements, `client_id`, réponse, actions, mise à jour en place), `app/Views/chat/index.php` (bouton trombone, zone d'aperçu, bandeaux « Réponse à … » / « Modification du message — Annuler », chargement du second script), `app/Views/layouts/app.php` si besoin, `public/assets/css/app.css`, `tests/e2e/smoke.js`

**Interfaces :**
- Consumes : routes et charges utiles des tâches 2–3 ; attributs de la tâche 4.
- Produces :
  - `window.kbChatAttachments = { pending(): Array<{id}>, busy(): boolean, clear(): void }` — lu par `chat.js` à l'envoi (« Envoyer » désactivé tant que `busy()`).
  - Réduction : photos dont le grand côté dépasse 2 000 px → canvas (`createImageBitmap` avec orientation, sinon `<img>`), JPEG qualité 0,85 ; échec → original ; original > 6 Mo non réductible → message exact « Cette photo est trop lourde… » sans envoi.
  - Envoi d'un fichier : `XMLHttpRequest` multipart (`file`, `_csrf`), progression annoncée (`aria-live`), « Réessayer » par fichier, croix pour retirer (la pièce non envoyée devient orpheline, purgée par le serveur).
  - `client_id` : 32 hex (`crypto.getRandomValues`) créé à la saisie de l'envoi, réutilisé par « Réessayer » ; stocké avec le texte non confirmé (`kb-unsent-{cid}` devient une liste d'objets `{text, client_id, attachment_ids}` ; lecture compatible avec l'ancienne liste de chaînes).
  - Actions : PC survol / clavier (bouton « Actions »), téléphone appui long 500 ms → menu : Répondre, Réagir (les 6 emojis), Modifier et Supprimer (seulement si `data-mine` et `data-created` < 15 min ; Supprimer passe par la modale de confirmation existante `data-confirm`). Évènements `message_edited` / `message_deleted` / `reaction` : remplacement en place de la bulle par `data-message-id`.
  - Visionneuse : clic sur une vignette → image grande en surcouche (`role="dialog"`, `aria-modal`, focus piégé, Échap / bouton / toucher pour fermer) ; sans JS, le lien ouvre l'image.
  - Construction DOM uniquement (`textContent`, `createElement`) ; aucun `innerHTML` avec des données.

- [ ] **Step 1 : tests de fumée en échec** (`smoke.js`, bloc à deux comptes du 4a) :
  - B joint une photo générée de 4000×3000 (fichier créé par le test) : la requête envoyée pèse moins que l'original ; la vignette apparaît chez A sans rechargement.
  - A répond au message de B : l'extrait cité apparaît chez B.
  - B réagit 👍 au message de A : le compteur apparaît chez A ; B modifie son dernier message : « modifié » et le nouveau texte apparaissent chez A.
  - A supprime un message : « Message supprimé » chez B.
  - Envoi coupé (`route.abort`) puis « Réessayer » : un seul message chez A (même `client_id`).
- [ ] **Step 2 :** smoke → FAIL.
- [ ] **Step 3 :** implémenter ; vérification visuelle PC et iPhone 13 (aperçu avant envoi, menu d'actions, visionneuse, réactions) selon `craft-floor.md`.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; smoke → OK trois fois de suite.
- [ ] **Step 5 :** commit « Messagerie : joindre des fichiers, répondre, réagir, modifier ».

### Task 6 : démonstration, documentation et vérification

**Files :**
- Modify : `database/seeds/demo.php` (une photo générée par GD et un .xlsx construit avec `ZipArchive` dans le groupe « Chantier Résidence Les Palmiers », une réponse citée, deux réactions), `docs/ARCHITECTURE.md`, `docs/DATABASE.md` (migration 0007), `docs/SECURITY.md` (contrôle des fichiers, téléchargement), `docs/ADMINISTRATION.md` (pièces jointes, espace disque), `docs/DEPLOYMENT.md` (checklist : extensions `zip`, `fileinfo`, `exif` ; `CHAT_STORAGE_WARN_MB` ; sauvegarde de `storage/private/chat`), `PLAN.md`

- [ ] **Step 1 :** `impeccable detect` sur le HTML rendu (conversation avec pièces jointes, PC et 390×844) → aucun avertissement hors faux positifs connus (cadre `.kb-chat`).
- [ ] **Step 2 :** seed vérifié sur une base vierge (`DB_DATABASE=kibarou_seedcheck`), puis base supprimée.
- [ ] **Step 3 :** documentation.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; `php bin/console cron:run` OK ; smoke → « Smoke UI OK ».
- [ ] **Step 5 :** commit « Lot 4b : démonstration, documentation et vérifications » ; push.
