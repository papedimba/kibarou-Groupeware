# Lot 4a — Messagerie, l'essentiel : conception

Statut : rédigé le 08/11/2026, relu par un constructeur indépendant, complété ; en attente d'approbation.
Références : cahier des charges, module 2 ; `PRODUCT.md` ; ADR 0004 (polling) ; lots 2 (annuaire, présence) et 3 (notifications, interrogation passive).

## 1. Intention

Permettre aux collaborateurs de se coordonner par écrit entre bureau et chantier, en privé ou en groupe, depuis un PC ou un téléphone sur réseau faible, sans dépendre d'outils extérieurs. La messagerie est livrée en trois temps (décision de l'utilisateur) : **4a l'essentiel** (ce document), 4b le confort (pièces jointes, images, réponses, modification, suppression, réactions), 4c l'organisation (salons, salons de service, accusés de lecture, « en train d'écrire », recherche).

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs (4a)**
- Conversations privées (à deux) et de groupe, messages texte.
- « Temps réel » par interrogation adaptative, sur un **journal d'évènements** extensible (approche B, choix de l'utilisateur).
- Non-lus par conversation et au total ; notification « Nouveau message » ; bouton « Écrire » de l'annuaire.
- Écran pensé pour le téléphone autant que pour le PC.
- **Confidentialité stricte** (choix de l'utilisateur) : seuls les participants lisent une conversation, administrateurs compris ; aucun écran de consultation des échanges d'autrui.

**Non-objectifs (4b, 4c, plus tard)**
- Pièces jointes, images, réponses citées, modification, suppression, réactions (4b).
- Salons, salons de service, accusés de lecture visibles, indicateur « en train d'écrire », recherche (4c).
- WebSocket / service temps réel externe (possible plus tard sans refonte grâce au journal d'évènements).
- Messages vocaux, appels.

**Anti-objectifs**
- Qu'une personne lise une conversation dont elle n'est pas (ou plus) membre, ou des messages antérieurs à son ajout dans un groupe.
- Que le contenu d'un message parte dans un email ou une alerte (écran verrouillé, boîte mail partagée).
- Une notification par message (flot) ; une notification pour une conversation qu'on est en train de lire.
- Une interrogation qui épuise l'hébergement mutualisé, ou qui maintient une session ouverte sans activité réelle.
- Perdre le texte en cours de saisie (rechargement, réseau qui coupe).

## 3. Contraintes

- Mutualisé, pas de processus permanent, pas de long polling (bloquerait un processus PHP par utilisateur).
- Structure existante, requêtes préparées, `e()`, CSP stricte (aucun script inline), français, WCAG AA, cibles 44 px.
- Réutiliser : interrogation passive du lot 3, `Notifier`, annuaire/présence du lot 2.
- Limites réelles de Likuid inconnues : les intervalles d'interrogation sont **réglables par configuration** sans modifier le code.

## 4. Approches envisagées

| Approche | Avantages | Inconvénients | Décision |
|---|---|---|---|
| A. Interroger directement les messages | Le plus simple pour 4a | Modifications, suppressions, réactions (4b) imposeraient un nouveau protocole | Écartée |
| **B. Journal d'évènements dès 4a** | Protocole stable pour 4b/4c ; c'est ce qu'un futur service temps réel diffuserait | Une table et une écriture de plus par message | **Retenue** (choix de l'utilisateur) |
| Rafraîchissement de page complet | Aucun JavaScript | Lourd sur réseau faible, perd la saisie | Écartée |
| Long polling / SSE | Latence faible | Un processus PHP bloqué par utilisateur sur mutualisé | Écartée |

## 5. Ce qui a été décidé

### 5.1 Données (migration 0005)

- `conversations` : `id`, `type` ENUM('direct','group'), `name` VARCHAR(80) NULL (groupes), `direct_key` VARCHAR(23) NULL **unique** (« idMin-idMax » : une seule conversation privée par paire), `created_by` (FK users SET NULL), `created_at`, `last_event_at` (tri de la liste).
- `conversation_members` : PK (`conversation_id`, `user_id`) ; `joined_event_id` BIGINT (un membre ne voit que les évènements d'id **supérieur**), `left_at` DATETIME NULL, `last_read_event_id` BIGINT DEFAULT 0, `last_read_at` DATETIME NULL.
- `messages` : `id`, `conversation_id`, `sender_id` (FK users SET NULL), `body` TEXT, `created_at`. (4b ajoutera `edited_at`, `deleted_at`, `reply_to_id`.)
- `chat_events` : `id` BIGINT auto (curseur global, croissant), `conversation_id`, `type` ENUM('message','member_added','member_left','renamed','created'), `message_id` NULL, `actor_id`, `target_user_id` NULL, `created_at` ; index (`conversation_id`, `id`). 4b/4c ajoutent des valeurs à l'ENUM par migration.
- Toutes les FK vers `conversations` en CASCADE ; écriture d'un message + son évènement + `last_event_at` dans **une transaction**.
- Nouvelle permission `chat.use`, donnée à tous les rôles système ; module `chat` activé (`modules.is_enabled = 1`, ce qui fait apparaître le bouton « Écrire » de l'annuaire).

### 5.2 Règles

- **Destinataires** : tout utilisateur actif ayant `chat.use`. Les comptes désactivés ne peuvent plus recevoir de nouveaux messages (envoi refusé avec un message clair dans une conversation privée avec eux) mais restent visibles dans l'historique.
- **Conversation privée** : unique par paire (clé `direct_key`) ; « Écrire » rouvre l'existante. On ne peut pas s'écrire à soi-même.
- **Groupe** : créé par n'importe quel détenteur de `chat.use`, nom obligatoire (1 à 80 caractères), 2 à 50 autres membres. Tout membre peut ajouter des membres (plafond 50), renommer, quitter. Un évènement visible « X a ajouté Y », « X a quitté le groupe », « X a renommé le groupe en … ». Un membre ajouté ne voit **que la suite** (pas l'historique antérieur) ; un membre parti ne voit plus la conversation ; s'il est ré-ajouté, il repart de ce moment-là.
- **Message** : texte de 1 à 4 000 caractères (espaces seuls refusés) ; affiché en texte brut échappé, retours à la ligne conservés, liens `http(s)://` rendus cliquables (`rel="noopener noreferrer"`, `target="_blank"`), aucun autre balisage.
- **Limite d'envoi** : 30 messages par minute et par personne (protection contre les boucles et le spam), au-delà 429 avec message clair.
- **Confidentialité** : chaque accès (page, API, envoi, lecture) vérifie l'appartenance active ; un identifiant de conversation n'est jamais une preuve. Aucun écran d'administration n'affiche le contenu des conversations ; le journal d'audit n'enregistre pas le contenu des messages (création de groupe et ajout/départ seulement).

### 5.3 Écran

- **Routes** : `/chat` (liste + état vide), `/chat/{id}` (liste + conversation), `/chat/new` (choisir une personne → conversation privée, ou plusieurs + un nom → groupe), `/chat/with/{userId}` (ouvre ou crée la conversation privée ; utilisé par l'annuaire). Entrée de menu « Messagerie » avec le total de non-lus.
- **PC** : liste à gauche (nom ou nom du groupe, photo/initiales, extrait du dernier message, heure relative, pastille de non-lus), conversation à droite, panneau « Détails » du groupe (membres, ajouter, renommer, quitter).
- **Téléphone** : la liste, puis la conversation en plein écran avec bouton retour ; zone de saisie fixée en bas, au pouce.
- **Conversation** : messages groupés par jour (« Aujourd'hui », « Hier », date), les miens à droite, ceux des autres à gauche avec le nom dans les groupes ; « Messages précédents » charge 50 messages plus anciens ; défilement automatique en bas à l'arrivée d'un message seulement si l'on est déjà en bas (sinon bouton « Nouveaux messages »).
- **Saisie** : Entrée envoie sur PC, Maj+Entrée va à la ligne ; sur téléphone, bouton « Envoyer » ; le brouillon est conservé localement par conversation (stockage du navigateur) et l'envoi qui échoue (réseau) reste affiché « Non envoyé — Réessayer » sans perte du texte.
- **Sans JavaScript** : la page affiche les messages et un formulaire classique d'envoi (rechargement), sans mise à jour automatique.

### 5.4 Interrogation adaptative

- `GET /api/chat/updates?since=<id d'évènement>` : évènements des conversations où je suis membre actif, `id > since` **et** `id > joined_event_id`, 200 au plus, avec les données nécessaires à l'affichage (expéditeur, texte échappé côté client, conversation) et les compteurs de non-lus des conversations touchées. **Une requête indexée** quand rien n'a changé.
- Rythme (réglable dans la configuration `chat.poll`) : **3 s** quand une conversation est ouverte et que la personne a interagi dans la dernière minute, **10 s** après 1 min sans interaction, **30 s** après 5 min ou sur la seule liste ; arrêt quand l'onglet est caché, reprise immédiate au retour.
- Hors messagerie, l'interrogation de 60 s du lot 3 renvoie aussi le **total de non-lus de la messagerie** (badge du menu), sans requête supplémentaire côté navigateur.
- **Session** (*choix de conception après relecture*) : les `GET /api/*` restent passifs. **Envoyer** un message prolonge la session. Le **marquage « lu »**, appelé automatiquement par la page, ne la prolonge que si la page signale une interaction réelle (touche, clic ou toucher) dans les **2 dernières minutes** (`active=1`) ; sinon il est passif. Ainsi un PC laissé ouvert sur un groupe actif se déconnecte normalement après 2 h sans activité.
- **Lu** : à l'ouverture d'une conversation et quand des messages d'une conversation ouverte et visible sont affichés, la page appelle `POST /api/chat/conversations/{id}/read` avec le dernier id d'évènement vu (met à jour `last_read_event_id`, `last_read_at`, jamais à la baisse). **Ce marquage marque aussi comme lues les notifications `chat.message` non lues de cette conversation** (*choix de conception après relecture*) : la cloche et le récapitulatif ne rappellent pas une conversation déjà lue, et la règle « une notification tant qu'elle n'est pas lue » repart à zéro.
- Charge estimée (hypothèse 50 utilisateurs, 15 avec une conversation ouverte et active) : ≈ 5 requêtes/s au pire, une requête SQL indexée chacune ; à confronter aux limites Likuid (checklist de mise en production) ; intervalles relevables par configuration.

### 5.5 Notifications

- Nouveau type `chat.message` : libellé « Nouveau message », email par défaut **récapitulatif**, alerte navigateur **oui**.
- Titre : « Nouveau message de {Prénom Nom} » (privée) ou « Nouveau message de {Prénom Nom} dans {groupe} » ; lien `/chat/{id}`. **Aucun extrait du message** (anti-objectif).
- Pas de notification si le destinataire a lu cette conversation dans les **2 dernières minutes** (`last_read_at`), ni s'il a déjà une notification `chat.message` **non lue** pour cette conversation (une seule tant qu'elle n'est pas lue).

### 5.6 Annuaire

- Le bouton « Écrire » de la fiche (masqué tant que le module `chat` était inactif) pointe vers `/chat/with/{id}` ; il n'apparaît que si le lecteur et la personne ont `chat.use` et que la personne n'est pas soi-même.

## 6. Laissé au constructeur

- Noms de classes et de vues dans `app/Modules/Chat/` ; découpage du JavaScript (fichier dédié `public/assets/js/chat.js` chargé sur les pages de messagerie) avec une interface de transport isolée (`poll` aujourd'hui) pour un futur WebSocket.
- Mise en forme exacte des bulles, des séparateurs de jour, des états.
- Données de démonstration (une conversation privée et un groupe « Chantier Résidence Les Palmiers »).

## 7. Critères de réussite

- Deux utilisateurs dans deux navigateurs : un message envoyé apparaît chez l'autre en moins de 5 s quand sa conversation est ouverte (test de fumée Playwright).
- Un non-membre, un membre parti ou un membre ajouté tardivement n'obtient ni la conversation ni les messages hors de sa période (tests d'intégration sur la page, l'API d'évènements, l'envoi et le marquage lu).
- Aucun contenu de message dans `notifications`, `mail_queue` ni `audit_logs` (tests).
- Une notification au plus par conversation tant qu'elle n'est pas lue ; aucune si la conversation vient d'être lue (tests).
- L'interrogation (GET) ne prolonge pas la session ; l'envoi (POST) la prolonge (tests).
- Tous les tests existants verts ; détecteur Impeccable sans avertissement sur les écrans de messagerie.
