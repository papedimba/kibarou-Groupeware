# PLAN — Kibarou Groupware

## 1. Rapport d'analyse initial (07/11/2026)

### État du dépôt
Le dépôt `papedimba/kibarou-Groupeware` était **vide** : aucun commit, aucun fichier, aucun schéma SQL, aucun README.
Il n'y avait donc rien à corriger, à conserver ou à supprimer : le projet démarre de zéro.

### Contraintes Likuid : ce qui est vérifié et ce qui ne l'est pas
Aucune documentation publique ne détaille les limites du plan mutualisé Likuid. Les points suivants sont des **hypothèses
à confirmer auprès de Likuid (support ou panneau d'administration)** avant la mise en production :

| Point | Hypothèse retenue | Impact si faux | Vérification |
|---|---|---|---|
| Version PHP ≥ 8.2 sélectionnable | Oui | Bloquant (le code utilise PHP 8.2) | Panneau d'administration |
| Extensions pdo_mysql, mbstring, fileinfo, openssl, intl | Présentes | Bloquant | `phpinfo()` temporaire |
| MySQL 8 ou MariaDB ≥ 10.4 | Oui | Faible (SQL compatible avec les deux) | Panneau |
| `mod_rewrite` + `.htaccess` autorisés | Oui | Bloquant (routage) | Test d'une URL propre |
| Racine web modifiable vers `public/` | Inconnu | Moyen : un `.htaccess` racine sert de filet de sécurité | Panneau |
| CRON en ligne de commande PHP, toutes les 5 min | Oui (« disponible ») | Moyen : emails et nettoyage retardés | Panneau CRON |
| SSH | Non confirmé | Faible : un assistant d'installation web est prévu | Support |
| SMTP sortant (port 587) | Oui | Moyen : emails en échec, l'application continue | Test `cron:run` |
| Limites CPU, processus, connexions SQL simultanées, requêtes/heure | **Inconnues** | Fort pour la messagerie (polling) | Support Likuid : demander les valeurs chiffrées |

> « Illimité » n'est jamais interprété comme une absence de limite. Le polling de la messagerie (lot 4) sera dimensionné
> à partir des valeurs chiffrées obtenues ; voir `docs/ARCHITECTURE.md` §5.

### Choix structurants
- **PHP natif structuré (MVC léger)** plutôt que Laravel : déploiement FTP simple, aucune commande requise en production,
  empreinte mémoire faible. Voir [ADR 0001](docs/ADR/0001-php-natif-sans-framework.md).
- **Sessions en base de données** : révocation des sessions et liste des appareils connectés. Voir [ADR 0002](docs/ADR/0002-sessions-en-base.md).
- **Emails et notifications en file d'attente, traités par CRON**. Voir [ADR 0003](docs/ADR/0003-file-emails-cron.md).
- **Messagerie par polling HTTP adaptatif** derrière une interface de transport remplaçable. Voir [ADR 0004](docs/ADR/0004-messagerie-polling.md).
- **Assets auto-hébergés et CSP stricte** (aucun CDN, aucun script inline). Voir [ADR 0005](docs/ADR/0005-csp-stricte-assets-locaux.md).

## 2. Lots fonctionnels

Légende : ✅ terminé et testé · 🔜 prochain · ⏳ planifié

| Lot | Contenu | État |
|---|---|---|
| **1. Socle + authentification + administration** | Routeur, configuration, PDO, migrations, sessions en base, CSRF, en-têtes de sécurité, erreurs ; connexion, déconnexion, réinitialisation, limitation des tentatives, changement de mot de passe imposé ; utilisateurs, services, fonctions, rôles et permissions, sessions, audit, tableau de bord admin ; file d'emails + CRON ; installation CLI et web | ✅ |
| 2. Annuaire + profil enrichi | Annuaire, fiche, organigramme par service, présence (statut déclaré + en ligne), deux téléphones, photo privée | ✅ |
| 3. Notifications | Centrale, cloche, préférences, alertes navigateur PC/Android, emails immédiats et récapitulatif à 7 h | ✅ |
| 4a. Messagerie : privé et groupes | Conversations privées, groupes, non-lus, interrogation adaptative, brouillons, envoi sans JavaScript | ✅ |
| 4b/4c. Messagerie : suite | Salons, salons de service, pièces jointes privées, réactions, réponses, modification, suppression logique, accusés, indicateur de saisie, recherche | 🔜 |
| 5. Calendrier + réunions | Événements, participants, invitations, rappels CRON, récurrence (RRULE simplifiée), export ICS ; convocations, ordre du jour, comptes rendus, décisions, actions | ⏳ |
| 6. Tâches + projets | Tâches, Kanban, commentaires, pièces jointes, historique ; projets, membres, jalons, progression | ⏳ |
| 7. Documents | Dossiers, versions, permissions, corbeille, téléchargement sécurisé, recherche | ⏳ |
| 8. Annonces | Ciblage par service, publication différée, expiration, accusés de lecture | ⏳ |
| 9. Workflows | Définitions configurables (étapes, validateurs), demandes, historique ; modèles congé, matériel, véhicule, informatique | ⏳ |
| 10. Durcissement + mise en production | Revue de sécurité, tests de charge du polling, sauvegardes automatisées, PWA (manifest, service worker) | ⏳ |

Ordre justifié : l'annuaire et les notifications sont des dépendances de la messagerie, des tâches et des workflows.

## 3. Règles de passage d'un lot au suivant
1. Tous les tests PHPUnit passent (`composer test`) sur MySQL/MariaDB réel.
2. Le test de fumée Playwright passe (`tests/e2e/smoke.js`).
3. Documentation mise à jour (DATABASE, SECURITY, DEPLOYMENT si concernés).
4. Aucune régression sur les lots précédents.

## 4. Journal des lots

### Lot 1 — terminé le 07/11/2026
- 53 tests PHPUnit (unitaires + intégration de bout en bout sur MariaDB 10.11) : tous verts.
- Test de fumée Playwright (Chromium, bureau + mobile) : vert, aucune erreur console ni violation CSP.
- Limites connues :
  - PHPStan n'a pas pu être installé (téléchargement bloqué par le réseau de l'environnement de développement) :
    la vérification statique s'est limitée à `php -l`. À ajouter dans un environnement disposant d'un accès complet.
  - La suppression définitive et l'anonymisation des comptes ne sont pas implémentées : un compte se désactive
    (la colonne `deleted_at` est prévue pour l'anonymisation RGPD d'un lot ultérieur).
  - Pas de double authentification (2FA) : recommandée pour les super administrateurs dans le lot 10.

### Corrections UX du lot 1 — 07/11/2026
- Revue Impeccable (critique en deux évaluations indépendantes) : 23/40, 1 P0 et 4 P1. Plan : `docs/superpowers/plans/2026-11-07-corrections-ux-lot1.md`.
- Corrigé : tableaux en cartes sur mobile ; cibles tactiles ≥ 44 px, texte 16 px sur mobile, contrastes AA ; journal d'audit en français ;
  création de compte sûre (récapitulatif unique, statut réel, confirmations nommées, anti double envoi, « Afficher » le mot de passe) ;
  accueil utile et erreurs 403/404 dans l'interface ; finition (surfaces navigateur, contrastes résiduels).
- Détecteur Impeccable sur le HTML rendu : 84 signalements → 1 avis volontaire (tirets « — » des cellules vides).
- Tests : 68 PHPUnit, test de fumée Playwright étendu (cibles tactiles, contrastes, statut visible sur mobile, confirmation, double envoi).

### Lot 2 — Annuaire, organigramme et présence — 08/11/2026
- Conception : `docs/superpowers/specs/2026-11-07-annuaire-presence-design.md` ; plan : `docs/superpowers/plans/2026-11-07-lot2-annuaire-presence.md`.
- Livré : annuaire avec recherche instantanée (accents ignorés), fiche, organigramme par service, statut déclaré du jour (bureau, chantier, déplacement, congé, absent) avec mise à jour groupée par le responsable, pastille « en ligne », téléphones professionnel / personnel, photo de profil réencodée et privée, nettoyage nocturne.
- Tests : 108 PHPUnit, test de fumée Playwright étendu ; détecteur Impeccable sans signalement sur les nouvelles pages.
- À vérifier chez Likuid : extension GD avec WebP (checklist de mise en production).

### Lot 3 — Notifications — 08/11/2026
- Conception : `docs/superpowers/specs/2026-11-08-notifications-design.md` ; plan : `docs/superpowers/plans/2026-11-08-lot3-notifications.md`.
- Livré : centrale d'envoi et catalogue des types, cloche et page « Mes notifications », préférences par type et par canal, interrogation passive toutes les 60 s, alertes navigateur sur PC et Android (service worker d'affichage), emails immédiats et récapitulatif quotidien à 7 h, branchements mot de passe / réactivation / statut, nettoyage.
- Tests : 146 PHPUnit ; test de fumée Playwright sur Chromium complet (alerte réellement affichée) ; détecteur Impeccable sans signalement.

### Lot 4a — Messagerie : conversations privées et groupes — 08/11/2026
- Conception : `docs/superpowers/specs/2026-11-08-messagerie-4a-design.md` ; plan : `docs/superpowers/plans/2026-11-08-lot4a-messagerie.md`.
- Livré : conversations privées (une par paire) et groupes (ajout, départ, renommage), journal d'évènements, non-lus par conversation et dans le menu, écrans PC (deux colonnes) et téléphone (liste puis conversation plein écran), envoi sans rechargement avec reprise après échec réseau, brouillons, envoi sans JavaScript, notifications sans contenu, limite de débit, session non prolongée par l'interrogation.
- Correctif au passage : alerte du navigateur perdue si le service worker n'était pas encore prêt (lot 3).
- Tests : 179 PHPUnit ; test de fumée Playwright avec échange réel entre deux comptes.
- À mesurer chez Likuid : charge de l'interrogation (checklist de mise en production).
