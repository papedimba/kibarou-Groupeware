# Lot 4c — Messagerie, l'organisation : conception

Statut : rédigé le 08/11/2026 ; relecture par un constructeur indépendant, puis approbation de l'utilisateur.
Références : conceptions 4a et 4b (`2026-11-08-messagerie-4a-design.md`, `…-4b-design.md`), code du module `app/Modules/Chat`, organisation (lot 2 : services, responsables, `users.department_id`).

## 1. Intention

Donner à la messagerie des **lieux ouverts** en plus des échanges privés : un salon par chantier ou par thème, que chacun rejoint de lui-même et dont il lit tout l'historique en arrivant (un conducteur de travaux qui reprend un chantier retrouve les décisions passées) ; un salon par service, tenu à jour d'après l'annuaire. Ajouter les **accusés de lecture** et la **recherche** dans ses messages.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs** (décisions de l'utilisateur, sauf mention « choix du concepteur »)
- **Salons** : listés pour toute l'entreprise, rejoints et quittés librement, **historique complet visible dès qu'on rejoint** ; créés, renommés et archivés par les **chefs de service et administrateurs** (nouvelle permission « Gérer les salons »).
- **Salons de service** : un par service actif, créé automatiquement ; membres = personnes du service, tenus à jour d'après l'annuaire (mutation = changement de salon) ; on ne peut pas le quitter ; historique complet.
- **Alertes** : dans les salons et salons de service, un nouveau message rend la conversation non lue **sans alerte par défaut** ; chacun peut activer les alertes d'un salon précis.
- **Accusés de lecture** : conversation privée → « Vu » sous mon dernier message lu par l'autre ; groupes et salons → « Vu par N » sous mes messages, liste des noms au toucher ; pas d'option pour masquer ses lectures.
- **Recherche** : « Rechercher dans mes messages » en haut de la liste ; texte et noms de fichiers ; conversations privées, groupes (depuis son arrivée), salons rejoints, salon de service ; 50 résultats les plus récents ; extrait avec le terme surligné ; un toucher ouvre la conversation à l'endroit du message.

**Non-objectifs** : indicateur « en train d'écrire » (reporté au lot 10, décision de l'utilisateur : une indication en retard trompe) ; salon « Toute l'entreprise » (doublon des annonces du lot 8) ; aperçu d'un salon avant de le rejoindre ; recherche plein texte avancée (opérateurs, accents ignorés au-delà de ce que fait la collation).

**Anti-objectifs**
- Un message d'un groupe ou d'une conversation privée trouvable par la recherche de quelqu'un qui n'en est pas membre, ou antérieur à son arrivée dans un groupe.
- Un salon de service dont la liste de membres diverge durablement de l'annuaire (une personne mutée qui continue de lire son ancien service).
- Les téléphones qui sonnent à chaque message d'un salon actif.
- Le journal d'évènements gonflé par une ligne à chaque lecture.

## 3. Contraintes

Mêmes que 4a/4b : mutualisé Likuid, interrogation adaptative (3 / 10 / 30 s), aucune connexion permanente, `cron:run` toutes les 5 minutes, CSP stricte, ~50 utilisateurs.

## 4. Approches

| | Principe | Pour | Contre | Décision |
|---|---|---|---|---|
| A | Tables séparées pour les salons | Indépendance | Tout refaire (envoi, pièces jointes, réactions, non-lus, interrogation, confidentialité) ; double maintenance | Écartée |
| B | Salons de service « virtuels » (membres calculés, sans ligne) | Jamais désynchronisés | Pas de non-lus, de position de lecture ni d'accusés | Écartée |
| **C** | **Deux nouveaux types de conversation (`channel`, `department`) sur le modèle existant** | Tout ce qui existe s'applique ; seules quelques règles changent | Synchronisation des salons de service à écrire | **Seule voie raisonnable (présentée à l'utilisateur, acceptée)** |

Synchronisation des salons de service : immédiate sur les actions de l'administration **et** rattrapage par `cron:run` (filet de sécurité). Accusés : pas d'évènement par lecture ; l'interrogation renvoie les positions de lecture de la conversation ouverte. Recherche : `LIKE` sur les conversations visibles (pas d'index plein texte : mal adapté au français, inutile à cette échelle). *Choix du concepteur, présentés à l'utilisateur sans objection.*

## 5. Conception

### 5.1 Données (migration 0008)

- `conversations.type` : + `channel`, `department` ; `department_id` INT NULL (FK departments, unique) ; `description` VARCHAR(200) NULL ; `archived_at` DATETIME NULL.
- `conversation_members.notify` TINYINT(1) NOT NULL : alertes de cette conversation pour ce membre ; valeur à l'arrivée 1 pour `direct`/`group`, 0 pour `channel`/`department`.
- Permission `chat.channels.manage` (« Gérer les salons »), donnée aux rôles `admin`, `chef_service` et `directeur` (*choix du concepteur* pour `directeur` : il est au-dessus des chefs de service).
- Migration : création d'un salon de service par service actif et ajout de ses membres (personnes actives avec `chat.use` dont `department_id` est ce service).

### 5.2 Règles

- **Historique complet** : un membre de salon ou de salon de service a `joined_event_id = 0` (il voit tout, y compris en rejoignant à nouveau). Les règles 4a/4b de visibilité restent identiques pour `direct` et `group`.
- **Position de lecture à l'arrivée** (salons, salons de service, y compris par synchronisation et par la migration) : `last_read_event_id` = dernier évènement de la conversation → aucun flot de non-lus pour un nouveau venu. Nouvelle colonne `conversation_members.entered_event_id` (dernier évènement au moment de l'arrivée ; pour `direct`/`group` = `joined_event_id`) : « Vu par N » ne compte pas un membre arrivé après le message (`entered_event_id` ≥ id du message) — *choix du concepteur*.
- **Salons** : nom 1–80 caractères, unique parmi les salons non archivés (« Un salon porte déjà ce nom. ») ; description facultative (200) ; le créateur en devient membre. Rejoindre / quitter : évènements `member_added` / `member_left` (« X a rejoint le salon », « X a quitté le salon »). Renommer, modifier la description, archiver, désarchiver : `chat.channels.manage` (renommer → évènement `renamed` existant ; archiver → nouvel évènement `archived`, et `unarchived`). Un salon archivé : plus de message, réaction, modification, suppression ni pièce jointe (« Ce salon est archivé : il n'accepte plus de messages. ») ; absent de la liste « Salons » sauf filtre « Archivés », **visible de tous** ; **tout le monde peut le rejoindre pour le lire** et le quitter (raison d'être des salons : retrouver les décisions d'un chantier, même terminé) — *choix du concepteur*. Un salon n'est jamais supprimé. Un salon de service ne s'archive pas à la main (il suit son service).
- **Salons de service** : nom = nom du service (suit les renommages, évènement `renamed`) ; membres = utilisateurs **actifs** ayant `chat.use` dont `department_id` est le service. Un service désactivé → salon archivé ; réactivé → désarchivé. Personne ne peut quitter ni ajouter de membre à la main ; on ne peut pas le renommer depuis la messagerie.
- **Synchronisation** : `ChatService::syncDepartments(?int $userId = null)` rapproche les membres actifs de chaque salon de service de l'annuaire (ajout `member_added` avec `actor_id = NULL` → « X a rejoint le salon » ; départ `member_left` ; aucun audit, aucune notification). Appelée après : création/modification d'un utilisateur (service, statut, rôles), activation/désactivation d'un compte, création/modification d'un service ; et à chaque `cron:run`.
- **Groupes** inchangés (4a/4b). Le 4a interdisait déjà « ajouter / renommer / quitter » hors groupe : ces règles s'étendent par type comme ci-dessus.
- **Alertes** : `notifyMembers` ne notifie que les membres `notify = 1` (en plus des règles 4a : pas de doublon non lu, pas si lu il y a moins de 2 minutes). Réglage « Alertes pour cette conversation » (interrupteur) dans l'en-tête de toute conversation ; `POST /chat/{id}/notify` (`on`=0|1). Le **compteur du menu** compte tous les non-lus, alertes ou non (*choix du concepteur* : le badge dit « il y a du nouveau », pas « on vous a sonné »).
- **Liste « Salons »** : `GET /chat/channels` : salons non archivés (nom, description, nombre de membres, dernière activité, « Rejoindre » ou « Ouvrir ») ; bouton « Nouveau salon » pour `chat.channels.manage`. La liste des conversations de chacun inclut ses salons et son salon de service, signalés par une icône (`hash` pour un salon, `building-2` pour un service).
- **Accusés de lecture** : position de lecture = `conversation_members.last_read_event_id` (déjà tenue). Quand une conversation est ouverte, `GET /api/chat/updates?since=…&open=<cid>` renvoie en plus `reads: [{user_id, name, last_read_event_id}]` des autres membres actifs (seulement si l'appelant en est membre). Affichage sur **mes** messages : privé → « Vu » sous le dernier de mes messages dont l'id ≤ position de l'autre ; groupes/salons → « Vu par N » (N = membres autres que moi dont la position ≥ id du message) sous mon dernier message seulement *(choix du concepteur : sous chaque message, ce serait du bruit)*, liste des noms au toucher. Rendu aussi par le serveur à l'ouverture.
- **Recherche** : `GET /chat/search?q=` (page complète, fonctionne sans JavaScript) ; 2 à 100 caractères ; `LIKE` avec `%` et `_` échappés ; sur `messages.body` (non supprimés) et `chat_attachments.original_name` ; limité aux conversations où l'appelant est membre actif et aux messages d'évènement > `joined_event_id` ; 50 résultats les plus récents ; résultat = nom de la conversation, auteur, date, extrait de ~120 caractères centré sur le terme, terme surligné (`<mark>` après échappement). Lien `/chat/{cid}?around=<message_id>` : la conversation s'ouvre avec l'historique centré sur ce message (25 avant, 25 après), message mis en évidence ; « Afficher les messages suivants » si besoin.
- **Notifications, audit** : création, renommage, archivage d'un salon audités (`chat.channel_created`, `chat.channel_updated`, `chat.channel_archived`), sans contenu ; rejoindre/quitter un salon non audité (anodin et visible dans le salon).

### 5.3 Interface

- Liste des conversations : en tête, champ de recherche puis deux liens « Salons » (liste à rejoindre) et « Nouvelle conversation ». Conversations, salons et salon de service mêlés, triés par activité, icônes distinctes.
- En-tête d'un salon : nom, description, nombre de membres ; « Quitter le salon » (salons seulement) ; interrupteur « Alertes » ; pour `chat.channels.manage` : « Modifier » (nom, description), « Archiver ». Salon archivé : bandeau « Salon archivé » et zone de saisie remplacée par le message d'archive.
- Écran « Salons » : liste (PC deux colonnes comme la messagerie ; téléphone plein écran), filtre « Archivés », formulaire « Nouveau salon » (nom, description).
- Recherche : page de résultats dans le panneau de droite (PC) ou plein écran (téléphone), retour à la liste.
- Accusés : mention discrète à droite de l'heure (« · Vu », « · Vu par 3 ») ; liste des noms dans une petite fenêtre accessible au clavier.

## 6. Tests attendus (Review Focus)

1. Recherche : un non-membre d'un groupe ne trouve rien de ce groupe ; un membre arrivé tard ne trouve pas les messages antérieurs ; un membre de salon arrivé tard trouve tout l'historique du salon ; messages supprimés jamais trouvés ; `%` et `_` cherchés littéralement.
2. Mutation de service : la personne quitte l'ancien salon de service et entre dans le nouveau (immédiatement par l'administration, et par `cron:run` si la base a été modifiée autrement) ; un compte désactivé quitte son salon de service.
3. Salon archivé : envoi, réaction, modification, pièce jointe refusés ; lecture possible.
4. Accusés : les positions de lecture ne sont renvoyées qu'aux membres de la conversation ouverte ; « Vu par N » exact après lecture par N membres.
5. Alertes : un message dans un salon ne crée aucune notification pour un membre `notify = 0`, en crée une pour un membre qui a activé les alertes.

## 7. Laissé au constructeur

Découpage en tâches, noms des méthodes et des vues, détails visuels (craft floor Impeccable, cohérence avec 4a/4b), forme exacte de l'extrait de recherche, pagination de la liste « Salons » (inutile à cette échelle).
