# Lot 5a — Calendrier : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** agenda personnel (semaine, mois, liste), évènements avec invitations, réponses, rappels, séries, disponibilités et abonnement iCalendar.

**Architecture :** module `app/Modules/Calendar/` : `EventRepository` (lectures, filtre de visibilité), `EventService` (règles, invitations, notifications), `SeriesService` (génération et édition des occurrences), `ReminderService` (appelé par `cron:run`), `IcsWriter` (RFC 5545), `CalendarController` / `EventController` / `FeedController` ; vues serveur sans JavaScript obligatoire. Migration `0009`.

**Tech Stack :** PHP 8.2 natif, MySQL/MariaDB, Bootstrap 5.3.3, Lucide, PHPUnit 11, Playwright (Chromium complet).

**Spec :** `docs/superpowers/specs/2026-11-08-calendrier-5a-design.md` (approuvée).

## Global Constraints

- Stockage en UTC ; affichage et saisie dans `app.timezone` ; journée entière en DATE (`start_date`/`end_date`, `start_at`/`end_at` NULL).
- Visibilité : vues semaine/mois/liste = **mes** évènements seulement (organisateur ou participant) ; page d'un évènement dont je ne suis pas participant → 404 ; disponibilités = « Occupé » sans titre/lieu ; `is_private` → absent des disponibilités ; annulé → n'occupe rien.
- Seul l'organisateur modifie/annule ; un participant répond (`pending|yes|maybe|no`), règle son rappel, se retire (= `no`).
- Rappels : 15 min par défaut ; valeurs NULL (aucun), 15, 60, 1440 ; journée entière : 1440 → la veille à 18 h locales, sinon 7 h le jour même ; jamais après le début ; jamais aux réponses `no` ; une seule fois (`reminded_at`).
- Séries : `weekdays`, `weekly` (jours), `monthly` (même numéro, mois sans ce jour sautés) ; fin obligatoire ≤ 1 an après la première occurrence et ≤ 260 occurrences.
- Notifications : `calendar.invited`, `calendar.updated`, `calendar.cancelled` (email immédiat + alerte), `calendar.reminder` (alerte, pas d'email par défaut), `calendar.response` (organisateur ; récapitulatif, pas d'alerte). Jamais de description dans une notification.
- Abonnement : jeton 32 octets aléatoires, stocké en SHA-256, montré une fois ; `GET /calendar/feed/{token}.ics` hors session ; mes évènements J-30 à J+365 ; 60 requêtes/heure/jeton.
- Permission `calendar.use` (tous les rôles système) ; module `calendar` activé.
- Aucun script ni style inline ; WCAG AA ; cibles ≥ 44 px ; textes en français.
- Fin de chaque tâche : `vendor/bin/phpunit`, `php bin/console cron:run`, `node tests/e2e/smoke.js`, `node tests/e2e/chat-regressions.js` verts.

## Review Focus

1. **Évènement d'autrui** : URL d'un évènement dont on n'est pas participant, `.ics` et disponibilités → ni titre, ni lieu, ni description ; « Privé » absent des disponibilités → `EventAccessTest` (tâche 1) et `AvailabilityTest` (tâche 4).
2. **Rappel** : une seule fois, dans la fenêtre, pas aux `no`, pas après le début, journée entière à 18 h la veille ou 7 h → `ReminderTest` (tâche 3).
3. **Série** : bornes (1 an, 260) ; « cette fois » n'affecte qu'une ligne ; « cette occurrence et les suivantes » épargne détachées et précédentes, garde les réponses (sauf changement d'horaire) → `SeriesTest` (tâche 2).
4. **Abonnement** : jeton faux ou ancien → 404 ; seulement mes évènements → `FeedTest` (tâche 5).
5. **Fuseau** : 9 h locales s'affiche 9 h et sort à l'heure UTC juste dans l'iCalendar, y compris autour d'un changement d'heure (fuseau de test `Europe/Paris`) → `EventServiceTest::testLocalTimeRoundTrip` (tâche 1).

---

### Task 1 : évènements, invitations, réponses, vues

**Files :**
- Create : `database/migrations/0009_calendar.sql`, `app/Modules/Calendar/{EventRepository,EventService,CalendarController,EventController}.php`, `app/Views/calendar/{index,_week,_month,_list,event,form}.php`, `tests/Integration/{EventServiceTest,EventAccessTest,CalendarPagesTest}.php`
- Modify : `app/Core/App.php` (`events(): EventService`), `routes/web.php`, `app/Views/layouts/app.php` (menu « Calendrier », icône `calendar-days`, `can('calendar.use')`), `app/Modules/Notifications/NotificationTypes.php` (5 types), `app/Modules/Admin/AuditLabels.php`, `public/assets/css/app.css`

**Migration 0009 :** tables de la spec §5.1 (`calendar_series`, `events`, `event_participants` avec `reminder_minutes`, `reminder_custom`, `reminded_at`, `calendar_feeds`) + table `calendar_feed_hits` (`token_hash`, `hit_at`, index) pour la limite ; permission `calendar.use` pour `admin`, `directeur`, `chef_service`, `collaborateur` ; `INSERT INTO modules` ou `UPDATE … is_enabled = 1` pour `calendar` selon la table existante.

**Interfaces :**
- Produces :
  - `EventService::create(array $actor, array $input): int` — `$input` : `title`, `all_day` (0/1), `start` / `end` (`Y-m-d H:i` locales, ou `Y-m-d` en journée entière), `location`, `description`, `is_private`, `reminder` (''|15|60|1440), `participants` (list<int>), `departments` (list<int>), et pour une série `repeat` (''|weekdays|weekly|monthly), `weekdays` (list<1..7>), `until` (`Y-m-d`) — délègue la série à la tâche 2 ; `ValidationException` (« Le titre est obligatoire. », « La fin doit suivre le début. », …).
  - `update(array $actor, int $eventId, array $input, string $scope = 'one'): void` (`scope` : `one` | `following`, tâche 2), `cancel(array $actor, int $eventId, string $scope = 'one'): void`, `respond(array $actor, int $eventId, string $response, string $scope = 'series'): void`, `setReminder(array $actor, int $eventId, ?int $minutes): void`.
  - `EventRepository::visibleEvent(int $userId, int $eventId): ?array` (participant sinon null), `forUser(int $userId, string $fromUtc, string $toUtc): array` (mes évènements non annulés + annulés récents marqués), `participants(int $eventId): array`.
  - Helpers de temps : `EventService::toUtc(string $local): string`, `toLocal(string $utc): DateTimeImmutable` (fuseau `app.timezone`).
  - Routes (`perm:calendar.use`) : `GET /calendar` (`view=week|month|list`, `date=Y-m-d`), `GET /calendar/events/new`, `POST /calendar/events`, `GET /calendar/events/{id}`, `GET /calendar/events/{id}/edit`, `POST /calendar/events/{id}`, `POST /calendar/events/{id}/cancel`, `POST /calendar/events/{id}/respond`, `POST /calendar/events/{id}/reminder`.

- [ ] **Step 1 : tests en échec**
  - `EventServiceTest::testCreateInviteNotifies` : A crée avec B et le service S (C, D) → participants A(`yes`), B, C, D (`pending`) ; 3 notifications `calendar.invited` sans la description.
  - `EventServiceTest::testOnlyOrganizerEdits` : B → `update`/`cancel` 403 ; B `respond('no')` → `calendar.response` pour A.
  - `EventServiceTest::testTimeChangeResetsResponsesAndNotifies` : B `yes` ; A déplace l'horaire → B `pending`, `calendar.updated` à B ; changer seulement la description → aucune notification.
  - `EventServiceTest::testLocalTimeRoundTrip` (Review Focus 5) : fuseau `Europe/Paris` ; 2026-03-30 09:00 locales → `start_at` 07:00 UTC ; 2026-03-27 09:00 → 08:00 UTC ; relu → 09:00.
  - `EventAccessTest::testNonParticipantSeesNothing` (Review Focus 1) : C non invité → `/calendar/events/{id}` 404, `/calendar` ne contient pas le titre.
  - `CalendarPagesTest::testWeekMonthListRender` : semaine, mois, liste contiennent le titre de mon évènement ; journée entière en bandeau ; formulaire de création rend les champs ; sans JavaScript, création → redirection vers l'évènement.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter 'EventServiceTest|EventAccessTest|CalendarPagesTest'` → FAIL.
- [ ] **Step 3 :** implémenter (lire `reference/craft-floor.md` d'Impeccable avant les vues ; grille semaine 7 h–20 h positionnée par classes CSS générées côté serveur — pas de style inline : hauteur et décalage par paliers de 15 min via classes `kb-ev-top-N`/`kb-ev-h-N` ; chevauchements en colonnes).
- [ ] **Step 4 :** suite, `cron:run`, smoke, régressions → verts ; rendu PC et iPhone 13 vérifié.
- [ ] **Step 5 :** commit « Calendrier : évènements, invitations et vues ».

### Task 2 : séries

**Files :**
- Create : `app/Modules/Calendar/SeriesService.php`, `tests/Integration/SeriesTest.php`
- Modify : `EventService.php` (création/édition/annulation/réponse avec `scope`), vues `form.php` (répétition) et `event.php` (« cette fois seulement » / « cette occurrence et les suivantes »)

**Interfaces :**
- Produces : `SeriesService::occurrences(string $kind, array $weekdays, DateTimeImmutable $firstLocal, DateTimeImmutable $untilLocal): list<DateTimeImmutable>` (pure, ≤ 260, `until` ≤ premier + 1 an sinon `ValidationException(['until' => 'Une série dure un an au plus.'])`) ; `SeriesService::rebuildFollowing(int $eventId, array $input): void` (conserve participants, réponses, rappels personnels ; réponses `pending` si l'horaire change ; une notification par personne).

- [ ] **Step 1 : tests en échec** (`SeriesTest`, Review Focus 3)
  - `testWeeklyMonthlyWeekdaysGeneration` : hebdo lun./jeu. sur 4 semaines → 8 ; mensuel le 31 de janvier à mai → janvier, mars, mai ; jours ouvrés sur une semaine → 5 ; `until` à +13 mois → refus ; 300 occurrences → refus.
  - `testOneOccurrenceOnly` : modifier l'occurrence 3 « cette fois » → seule elle change, `detached = 1`.
  - `testFollowingKeepsDetachedPastAndAnswers` : B `yes` sur la série ; « cette occurrence et les suivantes » depuis la 4ᵉ avec nouveau lieu → 4ᵉ et suivantes non détachées changent ; 1–3 et la 6ᵉ (détachée) non ; B reste `yes` ; une seule notification `calendar.updated` à B.
  - `testFollowingTimeChangeResetsAnswers` : changement d'heure depuis la 2ᵉ → B `pending` sur 2ᵉ et suivantes seulement.
  - `testCancelFollowingIncludesDetached` : annuler depuis la 4ᵉ → 4ᵉ, 5ᵉ, 6ᵉ (détachée) annulées ; 1–3 non.
  - `testRespondWholeSeriesOrOne` : réponse série → toutes les occurrences à venir ; « cette fois » → une seule.
- [ ] **Step 2 :** FAIL. **Step 3 :** implémenter. **Step 4 :** suite et vérifications → verts. **Step 5 :** commit « Calendrier : évènements répétés ».

### Task 3 : rappels

**Files :**
- Create : `app/Modules/Calendar/ReminderService.php`, `tests/Integration/ReminderTest.php`
- Modify : `bin/console` (`cron:run` → `reminders` dans le compte rendu), `EventService.php` (`setReminder`, remise à zéro de `reminded_at` si l'horaire change, rappel par défaut qui ne touche pas `reminder_custom = 1`), `app/Views/calendar/event.php` (choix du rappel personnel)

**Interfaces :**
- Produces : `ReminderService::run(DateTimeImmutable $nowUtc): int` (nombre de rappels envoyés) ; titre de notification « Rappel : {titre} à {HH:MM} » ou « Rappel : {titre} demain » / « aujourd'hui » pour la journée entière ; lien vers l'évènement.

- [ ] **Step 1 : tests en échec** (`ReminderTest`, Review Focus 2)
  - `testSendsOnceInWindow` : début à T+10 min, rappel 15 → envoyé ; second `run` → rien ; `no` → rien ; rappel NULL → rien.
  - `testNeverAfterStart` : `run` à T+1 min après le début, `reminded_at` vide → rien.
  - `testAllDayTimes` : journée entière demain, rappel 1440 → envoyé par `run` à 18:00 locales aujourd'hui, pas à 17:55 ; rappel 15 → envoyé à 07:00 locales le jour même.
  - `testPersonalReminderSurvivesDefaultChange` : B règle 60 ; A change le défaut à 15 → B garde 60, C passe à 15.
  - `testTimeChangeReArms` : rappel déjà envoyé, horaire décalé d'un jour → `reminded_at` vidé, renvoyé le moment venu.
- [ ] **Step 2 :** FAIL. **Step 3 :** implémenter. **Step 4 :** suite, `cron:run` (affiche `reminders`) → verts. **Step 5 :** commit « Calendrier : rappels ».

### Task 4 : disponibilités et présence

**Files :**
- Create : `app/Views/calendar/availability.php`, `tests/Integration/AvailabilityTest.php`
- Modify : `EventRepository.php` (`busy(array $userIds, string $fromUtc, string $toUtc): array<int, list<array{start:string,end:string,all_day:bool}>>`), `CalendarController.php` (`availability`), vues semaine/mois/liste (bandeau statut de présence du jour via `PresenceService::current`), `routes/web.php` (`GET /calendar/availability`)

- [ ] **Step 1 : tests en échec** (`AvailabilityTest`, Review Focus 1)
  - `testBusyWithoutDetails` : évènement de B → la page de A (`users[]=B`) montre un bloc « Occupé » et pas le titre ni le lieu ; évènement privé de B → aucun bloc ; B a décliné un évènement → aucun bloc ; annulé → aucun bloc.
  - `testPresenceShown` : B en congé jusqu'à vendredi → colonnes jusqu'à vendredi marquées « Congé » ; « Sur chantier » affiché comme information.
  - `testAtMostTenPeople` : 11 personnes → message « Dix personnes au plus. ».
- [ ] **Step 2 :** FAIL. **Step 3 :** implémenter. **Step 4 :** suite et vérifications → verts. **Step 5 :** commit « Calendrier : disponibilités ».

### Task 5 : iCalendar et abonnement

**Files :**
- Create : `app/Modules/Calendar/{IcsWriter,FeedController}.php`, `app/Views/calendar/feed_settings.php`, `tests/Integration/FeedTest.php`
- Modify : `routes/web.php` (`GET /calendar/events/{id}.ics` ; `GET /profile/calendar`, `POST /profile/calendar/feed` ; `GET /calendar/feed/{x:token}.ics` **hors** groupe `auth`), `app/Core/Router.php` seulement si le motif `.ics` l'exige, `app/Modules/Admin/AuditLabels.php` (`calendar.feed_generated`)

**Interfaces :**
- Produces : `IcsWriter::calendar(array $events, string $host): string` (CRLF, lignes pliées à 75 octets, échappement `\\ ; , \n`, `DTSTART`/`DTEND` en `…Z` ou `VALUE=DATE`, `STATUS:CANCELLED`, `UID:event-{id}@{host}`) ; `FeedController::feed`, `::download`, `::settings`, `::generate`.

- [ ] **Step 1 : tests en échec** (`FeedTest`, Review Focus 4)
  - `testFeedContainsOnlyMyEvents` : jeton de A → VEVENT de A, aucun de B ; évènement décliné absent ; hors J-30..J+365 absent.
  - `testBadAndOldTokens` : jeton faux → 404 ; régénération → ancien 404, nouveau 200 ; jeton montré une fois (page suivante ne le contient plus).
  - `testRateLimit` : 61ᵉ requête dans l'heure → 429.
  - `testIcsFormatAndEscaping` : titre « Visite ; chantier, lot 3 » échappé ; journée entière en `VALUE=DATE` ; 9 h `Europe/Paris` en `…T070000Z` l'été.
  - `testEventIcsForParticipantsOnly` : non-participant → 404.
- [ ] **Step 2 :** FAIL. **Step 3 :** implémenter. **Step 4 :** suite et vérifications → verts. **Step 5 :** commit « Calendrier : export et abonnement iCalendar ».

### Task 6 : démonstration, documentation et vérification

**Files :**
- Modify : `database/seeds/demo.php` (réunion de chantier hebdomadaire sur 3 mois organisée par Moussa avec le service Travaux ; visite d'un jour ; évènement privé), `tests/e2e/smoke.js` (A invite B ; B voit la notification et répond « J'y serai » ; A voit la réponse ; vue disponibilités affiche « Occupé »), `docs/ARCHITECTURE.md`, `docs/DATABASE.md` (0009 ; retirer `calendar_type`), `docs/SECURITY.md` (visibilité, jeton d'abonnement), `docs/ADMINISTRATION.md`, `docs/DEPLOYMENT.md` (`APP_TIMEZONE` à fixer — Bamako supposé, à confirmer), `PLAN.md`

- [ ] **Step 1 :** rendu PC et iPhone 13 (semaine, mois, liste, évènement, formulaire, disponibilités, abonnement) ; `impeccable detect` → aucun avertissement hors faux positifs connus.
- [ ] **Step 2 :** seed vérifié sur une base vierge puis supprimée.
- [ ] **Step 3 :** documentation.
- [ ] **Step 4 :** suite, `cron:run`, smoke, régressions → verts.
- [ ] **Step 5 :** commit « Lot 5a : démonstration, documentation et vérifications » ; push.
