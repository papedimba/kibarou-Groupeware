# Lot 2 — Annuaire, organigramme et présence : conception

Statut : rédigé le 07/11/2026, relu par un constructeur indépendant, complété des réponses de l'utilisateur ; en attente d'approbation.
Références : cahier des charges, module 7 (Annuaire) ; `PRODUCT.md` ; `PLAN.md` (lot 2).

## 1. Intention

Permettre à chacun des ~50 collaborateurs de Kibarou Construction — au bureau sur PC ou sur un chantier au téléphone, réseau faible, aisance numérique variable — de **trouver un collègue, savoir où il est aujourd'hui et le joindre**. L'annuaire est aussi le socle des lots suivants : la messagerie (lot 4) s'ouvrira depuis une fiche, la présence en ligne servira à la messagerie, un congé validé (lot 9) posera le statut « En congé ».

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Annuaire consultable par **tout collaborateur connecté** : photo, nom, fonction, service, téléphone professionnel, email, responsable hiérarchique, présence.
- Recherche instantanée par nom, fonction, service ou chantier.
- Organigramme **par service**.
- Présence : **statut déclaré** (lieu / disponibilité) affiché en premier + **pastille « en ligne »** automatique.
- Photo de profil téléversée de façon sûre et stockée en privé.
- Deux numéros : professionnel (visible de tous) et personnel (restreint).

**Non-objectifs (lots ultérieurs)**
- Messagerie : le bouton « Écrire » est prévu sur la fiche mais **masqué** tant que le module `chat` n'est pas activé (lot 4).
- Liste de chantiers reliée aux projets (lot 6) : le chantier est un **texte libre** pour l'instant.
- Statut « En congé » posé automatiquement par une demande validée (lot 9).
- Actualisation en direct de la pastille « en ligne » (arrive avec le système de polling du lot 4).
- Organigramme « par personne » (qui rend compte à qui).

**Anti-objectifs (échec même si « ça marche »)**
- Un statut périmé affiché comme vrai (« Au bureau » hier soir encore affiché aujourd'hui).
- Un numéro personnel visible de tous.
- De la surveillance : afficher l'heure précise de dernière activité (« vu à 14 h 32 »).
- Une photo servie par une URL publique, ou un fichier téléversé exécutable ou porteur de métadonnées (GPS).
- Un annuaire inutilisable sur téléphone en réseau faible.

## 3. Contraintes

- Hébergement mutualisé : PHP 8.2 natif, MySQL/MariaDB, pas de processus permanent ; tâches de fond par la tâche CRON existante (`bin/console cron:run`, toutes les 5 min).
- Structure existante (`app/Modules/*`, contrôleurs légers, services, requêtes préparées, vues échappées par `e()`, CSP stricte sans script ni style inline, migrations SQL versionnées).
- Accessibilité : WCAG 2.1 AA, cibles tactiles ≥ 44 px, texte 16 px sur mobile ; ton neutre et sobre (`PRODUCT.md`).
- Extension GD avec WebP : présente en développement, **à vérifier chez Likuid** (ajoutée à la checklist de mise en production). Sans GD, le téléversement de photo est refusé avec un message clair ; le reste de l'annuaire fonctionne.
- Tests : PHPUnit sur MySQL/MariaDB réel, test de fumée Playwright étendu.

## 4. Approches envisagées

| Approche | Avantages | Inconvénients | Décision |
|---|---|---|---|
| **1. Pages rendues par le serveur dans la structure actuelle** | Cohérent avec le lot 1, rapide sur réseau faible, peu de code, fonctionne sans JS | Pastille non rafraîchie en direct | **Retenue** (choix de l'utilisateur) |
| 2. Interface JavaScript sur une API JSON | Réutilisable par une future app mobile | Plus de code, page vide tant que le script charge, deux styles d'application | Écartée : pas de besoin d'app mobile aujourd'hui |
| 3. Réutiliser la liste d'administration des utilisateurs | Rien à créer | Mélange gestion et consultation, droits confus | Écartée |

## 5. Ce qui a été décidé

### 5.0 Le « responsable » d'une personne (décision de l'utilisateur)
Partout où ce document parle du **responsable** d'une personne P, il s'agit de **l'un ou l'autre** :
- son responsable hiérarchique direct (`users.manager_id` de P) ;
- le responsable du service de P (`departments.manager_user_id` du service `users.department_id` de P ; service de P seulement, pas les services parents).

Raison : les fiches seront souvent incomplètes au démarrage ; un chef de service doit voir et gérer son service dès le premier jour. Le cercle des personnes voyant un numéro personnel s'élargit d'autant, en connaissance de cause.

### 5.1 Accès
- Nouvelle permission `directory.view`, attribuée par migration à **tous les rôles système** (super_admin implicite, admin, directeur, chef_service, collaborateur). Les rôles personnalisés peuvent l'avoir ou non.
- Nouvelle entrée de menu « Annuaire » (icône `contact`), visible avec `directory.view`.
- `users.view` (administration) reste distincte.
- **Module « Annuaire »** (*choix de conception*) : la migration passe `modules.directory.is_enabled` à 1. L'accès aux pages dépend uniquement de `directory.view` ; les drapeaux de modules ne servent pour l'instant qu'à masquer ce qui n'existe pas encore (bouton « Écrire » tant que `modules.chat` vaut 0). Pas d'écran d'administration des modules avant le lot 10.

### 5.2 Annuaire (`GET /directory`)
- Une seule page avec **tous les collaborateurs actifs** (non désactivés, non supprimés), triés par nom. Avec ~50 personnes, pas de pagination.
- Chaque entrée : photo ou initiales, nom, fonction, service, statut déclaré du jour, pastille « en ligne », téléphone professionnel (lien `tel:`), email (lien `mailto:`).
- **Recherche** : champ en haut de page qui filtre instantanément la liste côté navigateur (nom, prénom, fonction, service, texte du chantier, insensible aux accents et à la casse). Sans JS, le même champ envoie `?q=` et le serveur filtre (même logique, en SQL).
- **Filtre par service** : liste déroulante (même double fonctionnement).
- Mobile : cartes empilées (motif `.kb-table-stack` ou cartes dédiées), boutons « Appeler » et « Email » de 44 px.

### 5.3 Fiche (`GET /directory/{id}`)
- Photo, nom, fonction, service (lien vers son service dans l'organigramme), responsable hiérarchique (lien vers sa fiche), email, téléphone professionnel.
- **Téléphone personnel** affiché uniquement au **responsable** de la personne (§5.0), aux **administrateurs** (`users.manage`) et à **la personne elle-même**. Il n'apparaît pas non plus dans les pages d'administration pour un simple détenteur de `users.view` (*choix de conception*).
- Statut déclaré complet (libellé, chantier, note, « jusqu'au … ») et pastille « en ligne ».
- Si le lecteur peut modifier le statut (§5.5) : bouton « Modifier le statut ».
- Bouton « Écrire » : masqué tant que `modules.chat.is_enabled = 0`.
- Collaborateur désactivé ou inexistant → 404 (page dans l'interface, lot 1).

### 5.4 Organigramme (`GET /directory/org`)
- Arbre des **services actifs** (via `departments.parent_id`), chaque service affichant : son responsable (`manager_user_id`) en tête, puis ses membres actifs (triés par nom, le responsable n'y est pas répété), avec photo/initiales, fonction et statut.
- Les collaborateurs **sans service** sont regroupés dans une section finale « Sans service ».
- Services repliables (`<details>/<summary>` natif, sans JS). Sur ordinateur tous ouverts ; sur téléphone tous repliés sauf celui de l'utilisateur (*choix de conception* : l'attribut `open` est posé côté serveur pour le service de l'utilisateur, et un petit script ferme les autres sous 768 px ; sans JS, tout est ouvert).
- Un service est affiché même vide s'il a des sous-services ; un service vide sans sous-service est masqué.

### 5.5 Présence

**Statut déclaré** — nouvelle table `user_presence` (une ligne par utilisateur au plus) :

| Colonne | Type | Rôle |
|---|---|---|
| `user_id` | INT, PK, FK users ON DELETE CASCADE | |
| `status` | ENUM('office','site','travel','leave','absent') | Au bureau, Sur chantier, En déplacement, En congé, Absent |
| `site_label` | VARCHAR(120) NULL | Nom du chantier (obligatoire si `site`, ignoré sinon) |
| `note` | VARCHAR(160) NULL | Note libre courte |
| `until_date` | DATE NULL | Dernier jour de validité (inclus) |
| `set_by` | INT NULL, FK users ON DELETE SET NULL | Auteur de la déclaration |
| `updated_at` | DATETIME (UTC) | |

- **Validité** : toute déclaration **commence le jour même** ; pas de déclaration à l'avance (*décision de l'utilisateur* : les congés planifiés arriveront avec les demandes de congé du lot 9). Sans `until_date`, le statut vaut pour **le jour de sa déclaration**, jour calculé dans le fuseau `APP_TIMEZONE`. Avec `until_date` (≥ aujourd'hui, ≤ aujourd'hui + 90 jours), il vaut jusqu'à ce jour inclus. Une nouvelle déclaration remplace la précédente.
- **Affichage** : un statut expiré n'est **jamais affiché** (filtré à la lecture), même si le nettoyage n'est pas encore passé. Sans statut valide : « Statut non renseigné ».
- **Nettoyage** : `cron:run` supprime les statuts expirés et ceux des comptes désactivés (dans `Maintenance`).
- **Qui peut modifier** le statut d'une personne : elle-même ; son **responsable** (§5.0) ; tout détenteur de `users.manage`. Contrôle côté serveur pour chaque modification.
- **Mise à jour groupée** : un responsable voit, depuis l'accueil, la liste des personnes dont il est le responsable (§5.0) et peut leur appliquer un même statut (ex. « Sur chantier : Résidence Les Palmiers ») en une validation. Chaque personne a une case à cocher ; celles qui ont déjà un statut valable sont **décochées par défaut** et leur statut actuel est affiché à côté, pour ne jamais écraser une information juste par mégarde (*choix de conception*). Une ligne d'audit par personne modifiée.
- **Audit** : toute modification faite par quelqu'un d'autre que la personne → `presence.updated` (avec la personne visée). Les modifications par la personne elle-même ne sont pas journalisées (trop de bruit, faible sensibilité).
- **Où modifier** : sélecteur rapide en tête de l'accueil (statuts en boutons de 44 px ; chantier, note et date dans un petit formulaire déplié pour « Sur chantier », « En déplacement », « En congé ») ; aussi depuis « Mon profil » et la fiche annuaire. « Effacer mon statut » disponible.

**Pastille « en ligne »**
- « En ligne » si la personne a une session dont `last_activity_at` date de moins de **5 minutes** ; sinon pas de pastille (pas de « hors ligne » affiché, pas d'heure).
- Calcul : une requête par page (`SELECT user_id FROM sessions WHERE user_id IS NOT NULL AND last_activity_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE`). La session n'écrit son activité qu'une fois par minute : précision d'environ 1 à 6 minutes, suffisante.
- Pastille accompagnée d'un texte accessible (« en ligne »), pas seulement de la couleur.

### 5.6 Téléphones
- Migration : colonne existante `users.phone` conservée et **renommée à l'affichage** « Téléphone professionnel » ; nouvelle colonne `users.phone_personal VARCHAR(30) NULL`.
- Saisie : la personne (profil : les deux numéros) et les administrateurs (formulaire utilisateur : les deux).
- Visibilité : professionnel → tous les détenteurs de `directory.view` ; personnel → §5.3.
- Libellés d'audit : `phone_personal` → « Téléphone personnel » dans `AuditLabels::FIELDS`.

### 5.7 Photo de profil
- Téléversement : par la personne (profil) ; téléversement, remplacement ou retrait par un administrateur (`users.manage`, fiche utilisateur).
- Contrôles : taille ≤ 5 Mo ; type détecté par `finfo` parmi `image/jpeg`, `image/png`, `image/webp` (jamais le type déclaré par le navigateur) ; décodage par GD obligatoire (un fichier qui ne se décode pas est refusé) ; dimensions source ≤ 6000×6000.
- Traitement : recadrage carré centré, redimensionnement à **256×256**, réencodage **WebP** (qualité ~82) — supprime EXIF/GPS et tout contenu non image.
- Stockage : `storage/private/avatars/<id>-<jeton aléatoire>.webp` ; le chemin relatif va dans `users.avatar_path` ; l'ancien fichier est supprimé au remplacement.
- Diffusion : `GET /directory/{id}/photo` réservé aux détenteurs de `directory.view` ; en-têtes `Content-Type: image/webp`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=86400`, URL versionnée par le jeton du fichier (cache invalidé au remplacement). Photo absente → 404 (la vue affiche les initiales sans appeler cette URL).
- Audit : `profile.photo_updated`, `user.photo_removed`.

### 5.8 Accueil
- Sélecteur rapide du statut en tête.
- Pour un responsable (§5.0) : bloc « Mon équipe aujourd'hui » (statut de chacun + mise à jour groupée).

## 6. Laissé au constructeur

- Noms exacts des classes, services et vues, dans le respect de la structure existante.
- Composant mobile des cartes de l'annuaire (réutiliser `.kb-table-stack` ou une grille de cartes).
- Couleurs des statuts (dans la palette existante, contraste AA, jamais la couleur seule).
- Normalisation des accents pour la recherche côté serveur (collation `utf8mb4_unicode_ci` suffit pour `LIKE`) et côté navigateur (`normalize('NFD')`).
- Découpage en tâches du plan et données de démonstration (seed) des statuts.

## 7. Critères de réussite

- Un collaborateur trouve un collègue par nom ou par chantier en moins de 5 secondes sur téléphone.
- Aucun numéro personnel visible hors des personnes autorisées (test d'intégration par rôle).
- Aucun statut expiré affiché (test avec date simulée).
- Photo : fichier non image, image piégée et image > 5 Mo refusés ; EXIF absent du fichier servi (tests).
- Tous les tests existants restent verts ; détecteur Impeccable sans avertissement sur les nouvelles pages.
