# Kibarou Groupware

Intranet collaboratif de **Kibarou Construction** (environ 50 utilisateurs) : authentification, administration,
messagerie instantanée, calendrier, tâches, projets, réunions, documents, annonces, workflows, annuaire et notifications.

Conçu pour un **hébergement mutualisé PHP/MySQL** (Likuid) : aucun processus permanent, aucun Node.js, Redis ni Docker
en production ; tâches de fond par CRON ; déploiement possible par FTP.

## État d'avancement

| Lot | Contenu | État |
|---|---|---|
| 1 | Socle, authentification, administration (utilisateurs, services, fonctions, rôles, sessions, audit) | ✅ Terminé |
| 2 → 10 | Annuaire, notifications, messagerie, calendrier et réunions, tâches et projets, documents, annonces, workflows, mise en production | Voir [PLAN.md](PLAN.md) |

## Démarrage rapide (développement)

Prérequis : PHP 8.2+ (`pdo_mysql`, `mbstring`, `fileinfo`), Composer, MySQL 8 ou MariaDB 10.4+.

```bash
composer install
cp .env.example .env             # puis : APP_ENV=local, APP_DEBUG=true, SESSION_SECURE=false, DB_*
php bin/console migrate
php bin/console admin:create     # premier super administrateur (interactif)
php bin/console seed:demo        # facultatif : services et comptes de démonstration (refusé en production)
php -S 127.0.0.1:8080 -t public tests/e2e/router.php
```

## Commandes

| Commande | Rôle |
|---|---|
| `php bin/console migrate` | Applique les migrations SQL en attente |
| `php bin/console admin:create` | Crée le premier super administrateur |
| `php bin/console cron:run` | Envoie les emails en file et nettoie les données expirées (CRON toutes les 5 minutes) |
| `php bin/console seed:demo` | Données de démonstration (hors production) |
| `composer test` | Tests PHPUnit (unitaires + intégration sur MySQL/MariaDB réel) |
| `node tests/e2e/smoke.js` | Test de fumée de l'interface (Playwright), voir l'en-tête du fichier |

## Tests

Les tests d'intégration exigent une base dédiée **qui est entièrement vidée à chaque lancement** :
```sql
CREATE DATABASE kibarou_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'kibarou'@'localhost' IDENTIFIED BY 'kibarou';
GRANT ALL ON kibarou_test.* TO 'kibarou'@'localhost';
```
Les identifiants se règlent dans `phpunit.xml` (`TEST_DB_*`).

## Documentation

- [PLAN.md](PLAN.md) : analyse initiale, hypothèses Likuid à vérifier, lots et journal
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) : architecture, conventions, conception de la messagerie, plan de tests
- [docs/DATABASE.md](docs/DATABASE.md) : schéma, migrations, modèle prévu
- [docs/SECURITY.md](docs/SECURITY.md) : mesures de sécurité, politique de conservation
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) : déploiement Likuid (SSH ou FTP), CRON, sauvegarde et restauration, checklist de mise en production
- [docs/ADMINISTRATION.md](docs/ADMINISTRATION.md) : guide de l'administrateur
- [docs/ADR/](docs/ADR/) : décisions d'architecture

## Licences des composants front

Bootstrap (MIT), Lucide (ISC), Inter (SIL OFL 1.1) : fichiers de licence dans `public/assets/vendor/*/LICENSE`.
