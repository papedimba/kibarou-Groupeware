# Lot 4b — Messagerie, le confort : conception

Statut : rédigé le 08/11/2026 ; relecture par un constructeur indépendant puis approbation de l'utilisateur.
Références : conception 4a (`2026-11-08-messagerie-4a-design.md`), code du lot 4a (`app/Modules/Chat`, `public/assets/js/chat.js`), `AvatarService` (lot 2) pour le traitement des images.

## 1. Intention

Les équipes bureau et chantier échangent surtout **des photos, des fichiers Word et des fichiers Excel** (réponse de l'utilisateur). Le 4b leur permet de le faire dans la messagerie privée, depuis un téléphone sur réseau faible, sans perdre l'essentiel du 4a (confidentialité stricte, interrogation passive, rien de perdu). Il ajoute le confort attendu d'une messagerie : répondre à un message précis, réagir, corriger ou retirer un message juste envoyé.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Pièces jointes : photos (JPEG, PNG, WebP), Word (.docx, .doc), Excel (.xlsx, .xls), PDF ; jusqu'à 10 par message ; un message peut ne contenir que des pièces jointes.
- Photos réduites (2 000 px au grand côté) : **seule la version réduite est conservée** (décision de l'utilisateur : « une photo réduite suffit »).
- Réponse citée, réactions (choix fixe), modification et suppression de ses propres messages **pendant 15 minutes** après l'envoi (décision de l'utilisateur).
- Envoi fiable sur réseau faible : progression par fichier, reprise d'un seul fichier, aucun doublon même après « Réessayer » (correctif reporté du 4a).
- Espace disque de la messagerie visible des administrateurs.

**Non-objectifs (4c ou plus tard)** : salons, salons de service, accusés de lecture visibles, « en train d'écrire », recherche (4c) ; aperçu des fichiers Word/Excel/PDF dans le navigateur ; vidéos, audio ; transfert d'un message vers une autre conversation ; historique des versions d'un message modifié ; purge automatique des anciens fichiers.

**Anti-objectifs (échec même si « ça marche »)**
- Un fichier lisible par quelqu'un qui n'est pas membre, ou par un membre arrivé après son envoi, ou après suppression du message.
- Un extrait cité, une modification ou une réaction qui révèle à un membre récent un message antérieur à son arrivée.
- Un contenu de message ou un nom de fichier dans une notification, un email ou le journal d'audit.
- Une photo qui empêche l'envoi sur un téléphone de chantier (trop lourde, bloquée par la limite de 8 Mo de l'hébergement).
- Un administrateur qui lit, modifie ou supprime le message d'un autre.

## 3. Contraintes

- Hébergement mutualisé Likuid : `upload_max_filesize = 6M`, `post_max_size = 8M` (`public/.user.ini`) ; mémoire PHP limitée ; GD avec WebP (déjà exigé par le lot 2) ; pas de LibreOffice ni d'ImageMagick ; espace disque limité et non chiffré à ce jour.
- Aucun processus permanent : nettoyage par `cron:run` (toutes les 5 minutes).
- CSP stricte existante : pas de script ni de style en ligne ; images servies par l'application (`img-src 'self'`).
- Le journal d'évènements du 4a reste le seul canal de diffusion : tout changement visible est un évènement.

## 4. Approches

| | Principe | Pour | Contre | Décision |
|---|---|---|---|---|
| A | Message et fichiers dans une seule requête | Le plus simple | Plusieurs photos dépassent 8 Mo ; une coupure oblige à tout renvoyer | Écartée |
| B | Chaque fichier envoyé seul d'abord, puis le message qui les cite | Sous la limite par requête ; reprise d'un seul fichier ; progression | Fichiers orphelins à nettoyer | Base retenue |
| **C** | **B + réduction des photos dans le navigateur avant envoi** | Une photo de 5 Mo n'en transmet que ~600 Ko : rapide sur le réseau du chantier, plus de refus pour taille | Un peu de JavaScript ; le serveur doit tout revérifier quand même | **Retenue (choix de l'utilisateur)** |

Modifications, suppressions, réactions et réponses : une seule voie sensée, des **types d'évènements supplémentaires** dans `chat_events` (prévu par la conception 4a), diffusés par l'interrogation existante.

## 5. Conception

### 5.1 Données (migration 0007)

- `messages` : ajout de `client_id` CHAR(32) NULL (identifiant hexadécimal créé par le navigateur, unique par expéditeur : index unique `(sender_id, client_id)`), `reply_to_id` BIGINT NULL (FK messages, SET NULL), `edited_at` DATETIME NULL, `deleted_at` DATETIME NULL.
- `chat_attachments` : `id`, `conversation_id` (FK CASCADE), `uploader_id` (FK users SET NULL), `message_id` NULL (FK messages CASCADE ; NULL tant que le message n'est pas envoyé), `kind` ENUM('image','file'), `original_name` VARCHAR(160) (nom assaini, affiché seulement), `stored_path` VARCHAR(120) (aléatoire, sans extension exécutable), `thumb_path` NULL (vignette des photos), `mime` VARCHAR(100), `size` INT, `width`/`height` NULL, `created_at` ; index `(message_id)`, `(message_id IS NULL, created_at)` → en pratique `(created_at)` pour le nettoyage.
- `chat_reactions` : clé `(message_id, user_id, emoji)`, `created_at`. `emoji` VARCHAR(16) parmi la liste fixe.
- `chat_events.type` : ajout de `message_edited`, `message_deleted`, `reaction`.
- Fichiers : `storage/private/chat/<AAAA>/<MM>/<aléatoire 32 hex>.<webp|bin>` ; la vignette `<même nom>-t.webp`.

### 5.2 Règles

- **Formats** (liste blanche, contrôle sur le contenu réel via `finfo`, jamais sur le type annoncé par le navigateur, et extension cohérente) :
  - images JPEG/PNG/WebP → décodées puis **réencodées en WebP** par GD (comme `AvatarService` : orientation EXIF corrigée, métadonnées et GPS supprimés), grand côté ramené à 2 000 px, vignette 480 px ; plafond de décodage 24 mégapixels ;
  - `.docx`/`.xlsx` (finfo : type OOXML ou `application/zip`) **et** ouverture par `ZipArchive` : `[Content_Types].xml` doit déclarer un document Word ou Excel, et la présence de `vbaProject.bin` (macros) entraîne le refus ; si l'extension zip manque sur l'hébergement, ces formats sont refusés plutôt qu'acceptés sans contrôle (vérification ajoutée à la checklist de mise en production) — *choix du concepteur* ; `.doc`/`.xls` (`application/msword`, `application/vnd.ms-excel`, ou `application/x-ole-storage`/CDF) ; `.pdf` (`application/pdf`) → conservés tels quels, jamais servis en ligne ;
  - les formats à macros (`.docm`, `.xlsm`) et tout le reste sont refusés : « Ce type de fichier n'est pas accepté. Formats possibles : photos, Word, Excel, PDF. »
- **Taille** : 6 Mo par fichier, 10 fichiers par message ; une photo que le navigateur n'a pas pu réduire et qui dépasse 6 Mo est refusée avec « Cette photo est trop lourde (6 Mo au maximum). Prenez-la en qualité réduite ou envoyez-la depuis un autre appareil. » — *choix du concepteur* (tous les navigateurs actuels réduisent la photo, quelle que soit sa définition) ; texte de message facultatif s'il y a au moins une pièce jointe (4 000 caractères au plus sinon, comme au 4a).
- **Envoi en deux temps** : `POST /api/chat/conversations/{id}/attachments` (un fichier, multipart, membre actif obligatoire) → `{"attachment": {id, kind, name, size, thumb_url}}` ; puis `POST …/messages` avec `body`, `client_id`, `attachment_ids[]`, `reply_to_id` facultatif. Le serveur n'accepte que des pièces jointes **de cet expéditeur, de cette conversation, non encore rattachées** ; il les rattache dans la même transaction que le message. Limite : 30 fichiers par minute et par personne (429).
- **Pas de doublon** : un `client_id` déjà connu pour cet expéditeur renvoie le message existant (200) au lieu d'en créer un second. Le navigateur réutilise le même `client_id` pour « Réessayer ».
- **Orphelins** : pièces jointes non rattachées après 24 h effacées (base et disque) par `cron:run`.
- **Réponse** : `reply_to_id` doit désigner un message de la même conversation visible par l'expéditeur. Affichage d'un extrait (auteur + 80 premiers caractères, ou « Photo » / « Fichier : nom ») ; « Message supprimé » si l'original l'est.
- **Modifier** (texte seulement, pas les pièces jointes) et **supprimer** : auteur seulement, **15 minutes** après `created_at` au plus, contrôlé par le serveur (l'interface masque les actions après coup). Modification : `body` remplacé, `edited_at` renseigné, évènement `message_edited` ; mention « modifié ». Suppression : `body` vidé, `deleted_at` renseigné, fichiers effacés du disque et lignes de pièces jointes supprimées, réactions supprimées, évènement `message_deleted` ; affichage « Message supprimé » pour tous. Audit : `chat.message_deleted` sans contenu (la modification n'est pas auditée : elle reste visible par la mention).
- **Réactions** : 👍 ✅ 👀 🙏 ❤️ 😂 ; une personne peut poser plusieurs réactions différentes sur un message, chacune bascule (ajout/retrait) ; évènement `reaction` ; aucune notification.
- **Visibilité d'un membre arrivé tard** (règle centrale) : un évènement `message_edited`, `message_deleted` ou `reaction` n'est livré que si l'évènement `message` d'origine est lui-même visible (id > `joined_event_id`). Un extrait cité d'un message antérieur à l'arrivée s'affiche « Message antérieur à votre arrivée ». Une pièce jointe n'est servie que si son message est visible et non supprimé.
- **Téléchargement** : `GET /chat/attachments/{id}` (et `/thumb`) : membre actif + message visible + non supprimé, sinon 404. Photos : `Content-Type: image/webp`, en ligne. Autres : `Content-Disposition: attachment; filename*=` (nom d'origine), `X-Content-Type-Options: nosniff`, `Cache-Control: private`.
- **Activité de la conversation** : seul un nouveau message (ou un évènement de membre, comme au 4a) met à jour `last_event_at` et fait remonter la conversation ; une modification, une suppression ou une réaction ne change ni l'ordre de la liste ni les non-lus, et ne met à jour l'extrait de la liste que si le message concerné est le dernier — *choix du concepteur*.
- **Notifications et liste** : la notification reste « Nouveau message » sans contenu. Extrait de la liste : texte, sinon « Photo » / « Fichier : nom » / « Message supprimé ».
- **Espace disque** : la *Vue d'ensemble* de l'administration affiche la taille totale des pièces jointes (somme de `size`) ; avertissement au-delà de `CHAT_STORAGE_WARN_MB` (défaut 2 000).

### 5.3 Évènements (API inchangée, charges utiles enrichies)

Chaque évènement `message` porte désormais `client_id`, `edited_at`, `deleted`, `reply_to` (`{id, sender_name, excerpt}` ou `{id, hidden: true}`), `attachments` (`[{id, kind, name, size, width, height, url, thumb_url}]`), `reactions` (`[{emoji, count, mine, names}]`). `message_edited`, `message_deleted` et `reaction` portent `message_id` et l'état à jour du message ; le navigateur remplace la bulle en place (dédoublonnage par id d'évènement inchangé).

### 5.4 Interface

- **Joindre** : bouton trombone à gauche de la zone de saisie (`<input type="file" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx">`) ; sur téléphone, le choix système propose appareil photo et galerie. Les fichiers choisis s'affichent au-dessus de la saisie (vignette ou carte, barre de progression, croix pour retirer, « Réessayer » en cas d'échec) ; « Envoyer » attend la fin des envois.
- **Réduction dans le navigateur** : photos > 2 000 px redimensionnées (canvas, JPEG qualité 0,85) avant envoi ; en cas d'échec ou de navigateur trop ancien, envoi de l'original (le serveur réduit).
- **Bulle** : vignettes en grille (1 à 4 visibles, « +N »), toucher → image en grand (plein écran simple, fermeture Échap/bouton/toucher) ; cartes de fichier (icône Lucide selon le type, nom, taille, « Télécharger ») ; extrait cité en tête ; « modifié » à côté de l'heure ; compteurs de réactions sous la bulle (toucher → ajoute/retire la sienne ; noms en info-bulle et pour les lecteurs d'écran).
- **Actions sur un message** : PC au survol, téléphone par appui long → menu : Répondre, Réagir, Modifier et Supprimer (siens, < 15 min, avec confirmation pour supprimer). Modification dans la zone de saisie (bandeau « Modification du message — Annuler »).
- **Sans JavaScript** : lecture complète (images, fichiers, extraits cités, réactions, mention « modifié ») ; l'envoi de texte reste possible ; joindre, réagir, modifier, supprimer demandent JavaScript (formulaire classique hors périmètre).
- Accessibilité : actions joignables au clavier (bouton « Actions » visible au focus), cibles 44 px, textes alternatifs (« Photo envoyée par X »), progression annoncée.

## 6. Tests attendus (Review Focus)

1. Pièce jointe d'un autre expéditeur, d'une autre conversation ou déjà rattachée → refusée à l'envoi du message.
2. Non-membre, membre parti, membre arrivé après l'envoi, message supprimé → 404 sur le fichier et la vignette.
3. Membre arrivé tard : aucune modification, suppression, réaction ni extrait cité d'un message antérieur ne lui est livré.
4. Faux fichier (script renommé `.jpg` ou `.docx`, PDF déguisé, `.docm`) → refusé ; image réencodée sans EXIF/GPS.
5. Même `client_id` envoyé deux fois → un seul message.
6. Modifier/supprimer à 15 min + 1 s, ou le message d'un autre → refusé ; suppression → fichiers absents du disque.
7. Orphelin de plus de 24 h → effacé par `cron:run`.
8. Test de fumée : photo réduite dans le navigateur (taille envoyée < original), affichée chez l'autre sans rechargement ; réaction et modification visibles chez l'autre.

## 7. Laissé au constructeur

Découpage en tâches, noms des méthodes, structure de `chat.js` (fichier unique ou second fichier pour les pièces jointes), détails visuels dans le respect du craft floor Impeccable et des écrans 4a, choix exact de l'icône par type de fichier.
