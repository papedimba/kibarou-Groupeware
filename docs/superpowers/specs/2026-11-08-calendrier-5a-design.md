# Lot 5a — Calendrier : conception

Statut : rédigé le 08/11/2026 ; relecture par un constructeur indépendant, puis approbation de l'utilisateur.
Références : PLAN.md (lot 5) ; notifications (lot 3) ; présence (lot 2, `user_presence`) ; `cron:run`.

## 1. Intention

Donner à chacun un agenda dans l'intranet, partagé avec ses collègues sans exposer le détail : planifier un rendez-vous ou une visite de chantier, inviter des personnes ou un service, être rappelé à temps, trouver un créneau où tout le monde est libre, et retrouver ses évènements dans Outlook ou sur son téléphone. Le lot 5b (réunions : ordre du jour, compte rendu, décisions, actions) s'appuiera sur ces évènements. L'utilisateur a demandé une proposition et l'a acceptée telle quelle (« OK »).

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Vues semaine, mois et liste ; sur téléphone, jour et liste. Congés et statuts du jour (présence) visibles.
- Évènement : titre, début/fin ou journée entière, lieu (texte libre), description ; confidentialité : collègues → « Occupé » sans détail, participants → détail ; case « Privé » → le créneau n'apparaît même pas aux autres.
- Invitations à des personnes ou à un service entier ; réponses « J'y serai » / « Peut-être » / « Je n'y serai pas » ; notifications à l'invitation, à la modification, à l'annulation.
- Rappels : 15 min avant par défaut ; aucun, 15 min, 1 h ou la veille ; cloche, alerte navigateur, email selon les préférences.
- Répétition : chaque jour ouvré, chaque semaine (jours choisis), chaque mois, jusqu'à une date ; modifier « cette fois seulement » ou « toute la série ».
- Disponibilités de plusieurs personnes côte à côte (« Occupé » et congés seulement).
- Abonnement en lecture seule de son agenda (Outlook, Google, téléphone) par lien secret ; téléchargement `.ics` d'un évènement.

**Non-objectifs** : agendas d'équipe ou partagés distincts des agendas personnels (prévus dans l'analyse initiale, écartés pour la simplicité — *choix du concepteur*) ; congés déclarés à l'avance (décision du lot 2 : statut du jour seulement ; les congés planifiés viendront avec les demandes du lot 9) ; réservation de salles ou de véhicules (lot 9) ; visioconférence intégrée (un lien dans la description suffit) ; synchronisation dans les deux sens avec Outlook/Google ; glisser-déposer ; séries sans fin ; réunions (lot 5b).

**Anti-objectifs**
- Un collègue qui lit le titre, le lieu ou la description d'un évènement dont il n'est pas participant.
- Un évènement « Privé » qui apparaît, même comme « Occupé », à quelqu'un d'autre que ses participants.
- Un rappel qui n'arrive pas, ou qui arrive à quelqu'un qui a décliné.
- Le lien d'abonnement d'une personne qui donne accès à l'agenda d'une autre.

## 3. Contraintes

Mutualisé Likuid, PHP natif, pas de processus permanent ; `cron:run` toutes les 5 minutes (rappels à ±5 min) ; tout stocké en UTC, affiché dans `app.timezone` ; CSP stricte ; ~50 utilisateurs ; WCAG AA, cibles 44 px ; fonctionne sans JavaScript.

## 4. Approches (choisies par l'utilisateur)

| Choix | Option retenue | Option écartée | Raison |
|---|---|---|---|
| Répétition | **Chaque occurrence enregistrée**, rattachée à sa série (fin obligatoire, 1 an au plus) | Règle seule + exceptions calculées à l'affichage | Affichage, rappels, disponibilités et export restent de simples requêtes ; « cette fois seulement » = modifier une ligne |
| Affichage | **Agenda rendu par le serveur**, style de l'intranet, formulaires | Bibliothèque FullCalendar | Sans JavaScript, accessible, léger sur réseau de chantier, cohérent avec le reste |

## 5. Conception

### 5.1 Données (migration 0009)

- `calendar_series` : `id`, `organizer_id`, `kind` ENUM('weekdays','weekly','monthly'), `weekdays` SET('1'..'7') NULL (hebdomadaire), `until_date` DATE, `created_at`.
- `events` : `id`, `series_id` NULL (FK CASCADE), `organizer_id` (FK users), `title` VARCHAR(150), `location` VARCHAR(150) NULL, `description` TEXT NULL, `all_day` TINYINT, `start_at`/`end_at` DATETIME UTC (journée entière : `start_date`/`end_date` DATE, `start_at`/`end_at` NULL), `is_private` TINYINT, `reminder_minutes` SMALLINT NULL (NULL = aucun ; 15, 60, 1440), `status` ENUM('confirmed','cancelled'), `detached` TINYINT (occurrence modifiée « cette fois seulement »), `created_at`, `updated_at` ; index (`start_at`), (`start_date`), (`series_id`, `start_at`).
- `event_participants` : (`event_id`, `user_id`) PK, `response` ENUM('pending','yes','maybe','no'), `reminder_minutes` SMALLINT NULL + `reminder_custom` TINYINT (rappel personnel), `reminded_at` DATETIME NULL, `responded_at` ; index (`user_id`, `event_id`). L'organisateur est participant (`yes`).
- `calendar_feeds` : `user_id` PK, `token_hash` CHAR(64), `created_at` (lien d'abonnement ; le jeton n'est montré qu'à sa création).
- Permission `calendar.use` (« Utiliser le calendrier »), donnée à tous les rôles système ; module `calendar` activé.
- Types de notification : `calendar.response` (réponse d'un invité → organisateur ; récapitulatif, pas d'alerte), `calendar.invited` (email immédiat, alerte navigateur), `calendar.updated` (email immédiat, alerte), `calendar.cancelled` (email immédiat, alerte), `calendar.reminder` (alerte navigateur, **pas d'email par défaut**), modifiables dans les préférences — *choix du concepteur*.

### 5.2 Règles

- **Qui voit quoi** : participant (y compris organisateur) → tout ; autre utilisateur → créneau « Occupé » sans titre ni lieu, sauf `is_private` → rien ; un évènement annulé n'occupe plus de créneau. Contrôlé côté serveur pour chaque page, export et API.
- **Qui modifie** : l'organisateur seul (titre, horaires, lieu, description, participants, rappel par défaut, confidentialité, annulation). Un participant répond, règle son propre rappel (*choix du concepteur* : `reminder_minutes` sur la ligne participant, initialisé depuis l'évènement), ou se retire (= « Je n'y serai pas »).
- **Inviter un service** : ajoute les membres actifs du service au moment de l'invitation (instantané, pas de suivi des mutations) — *choix du concepteur*.
- **Notifications** : invitation → chaque invité ; modification des horaires, du lieu ou du titre → participants sauf ceux qui ont décliné, réponses remises à « en attente » si l'horaire change (*choix du concepteur*) ; annulation → participants sauf ceux qui ont décliné. Pas de notification pour une simple réponse, sauf à l'organisateur dans le récapitulatif (`calendar.response`, email récapitulatif, pas d'alerte).
- **Rappel personnel** : à l'invitation, `reminder_minutes` du participant = celui de l'évènement ; s'il le change, `reminder_custom = 1` et un changement du rappel par défaut par l'organisateur ne le touche plus. Un changement d'horaire remet `reminded_at` à vide pour tous (le rappel repart) — *choix du concepteur*.
- **Rappels** : `cron:run` envoie `calendar.reminder` aux participants dont la réponse n'est pas `no`, quand `start - reminder ≤ maintenant < start` et `reminded_at` vide ; journée entière : la veille à 18 h locales pour « la veille », sinon à 7 h le jour même. Rattrapage : un rappel manqué pendant une panne du CRON n'est plus envoyé après le début.
- **Répétition** : `weekdays` (lun.–ven.), `weekly` (jours cochés), `monthly` (même numéro de jour ; mois sans ce jour sautés) ; fin obligatoire, au plus 1 an après la première occurrence et 260 occurrences. Création : toutes les occurrences sont créées. « Cette fois seulement » : l'occurrence est modifiée et marquée `detached`. « Toute la série » = **cette occurrence et les suivantes** (les précédentes ne bougent pas) : appliqué aux occurrences non détachées à partir de celle-ci ; un changement de règle ou de fin supprime ces occurrences et les recrée **en conservant les participants, leurs réponses et leurs rappels personnels** (réponses remises « en attente » seulement si l'horaire change) ; **une seule notification** par personne pour toute la série. Répondre : par défaut à toute la série, option « cette fois seulement ». Annuler la série à venir annule aussi les occurrences détachées à venir — *choix du concepteur*.
- **Disponibilités** : jusqu'à 10 personnes, une semaine ; blocs « Occupé » (évènements non annulés, non privés, réponse ≠ `no`) et statut de présence en cours (`user_presence`, étendu jusqu'à sa date de fin) : congé et absence comptent comme indisponibles ; chantier et déplacement sont affichés à titre d'information.
- **Limite de l'abonnement** : 60 requêtes/heure par jeton, comptées en base (pas de mécanisme existant à réutiliser) — *choix du concepteur*.
- **Abonnement** : `GET /calendar/feed/{token}.ics` (hors session, jeton de 32 octets aléatoires comparé par empreinte) ; contenu : mes évènements (organisateur ou participant ≠ `no`) de J-30 à J+365, au format iCalendar (RFC 5545, UID stable `event-{id}@{host}`, heures en UTC) ; régénérer le lien invalide l'ancien ; limite 60 requêtes/heure par jeton. `.ics` d'un évènement : réservé aux participants.
- **Audit** : génération / régénération du lien d'abonnement ; annulation d'un évènement par l'organisateur. Pas de contenu.

### 5.3 Interface

- Menu « Calendrier » (`calendar-days`). Page `/calendar` : semaine par défaut (PC), liste par défaut (téléphone, par lien « Liste »), mois ; navigation précédent / aujourd'hui / suivant par liens.
- Semaine : colonnes jours, plage 7 h–20 h, évènements positionnés par CSS (hauteur selon la durée), journée entière et congés en bandeau en haut ; chevauchements côte à côte.
- Évènement : page de détail (participants et réponses, boutons de réponse, rappel personnel, `.ics`), formulaire de création/modification (choix des invités par la liste filtrable déjà utilisée pour les groupes, ajout d'un service), choix « cette fois / toute la série » quand c'est une série.
- Disponibilités : `/calendar/availability?users[]=…&week=…` grille personnes × jours.
- Préférences : `/profile/calendar` (lien d'abonnement : générer, régénérer, instructions Outlook / Google / iPhone).

## 6. Tests attendus (Review Focus)

1. Non-participant : jamais de titre/lieu/description (page, liste, mois, disponibilités, `.ics`, abonnement) ; « Privé » invisible même comme « Occupé ».
2. Rappel : envoyé une seule fois, à la bonne fenêtre, pas aux réponses `no`, pas après le début ; journée entière aux heures prévues.
3. Série : 1 an / 260 occurrences au plus ; « cette fois seulement » n'affecte que l'occurrence ; « toute la série » épargne les occurrences détachées et passées.
4. Abonnement : mauvais jeton → 404 ; ancien jeton après régénération → 404 ; le flux ne contient que mes évènements.
5. Fuseau : un évènement créé à 9 h locales s'affiche à 9 h et sort à l'heure UTC correcte dans l'iCalendar ; changement d'heure d'été géré par le fuseau configuré.

## 7. Laissé au constructeur

Découpage en tâches, noms des classes (module `app/Modules/Calendar`), détails visuels (craft floor Impeccable), forme exacte de la grille semaine, pagination de la liste.
