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
| 2. Annuaire + profil enrichi | Photo (upload privé), organigramme, recherche, présence de base | 🔜 |
| 3. Notifications | Table `notifications`, préférences, centre de notifications, emails via la file, notifications navigateur | ⏳ |
| 4. Messagerie | Conversations privées, groupes, salons, salons de service, pièces jointes privées, réactions, réponses, modification, suppression logique, lus/non lus, accusés, présence, indicateur de saisie, recherche, polling adaptatif | ⏳ |
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
