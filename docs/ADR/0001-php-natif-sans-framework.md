# ADR 0001 — PHP natif structuré, sans framework

- Statut : accepté (07/11/2026)

## Contexte
Hébergement mutualisé Likuid, SSH non confirmé, déploiement potentiellement par FTP, environ 50 utilisateurs.
Laravel ou Symfony impliquent des commandes en production (cache, migrations Artisan), une empreinte mémoire et un
volume de fichiers plus importants, et des mises à jour majeures régulières.

## Décision
Monolithe modulaire en PHP 8.2 natif : petit noyau maison (`app/Core`, environ 1 000 lignes : routeur, middlewares,
session, PDO, migrations SQL, vues PHP, validation), modules par domaine, Composer pour l'autoload PSR-4 et quelques
dépendances ciblées (PHPMailer ; PHPUnit en développement).

## Conséquences
- (+) Déploiement par simple copie de fichiers, aucune commande obligatoire sur le serveur.
- (+) Code entièrement lisible et maîtrisé, surface d'attaque réduite.
- (−) Les fonctionnalités de framework (ORM, files de messages, etc.) doivent être écrites au besoin : on se limite au strict nécessaire.
- (−) Responsabilité de sécurité du noyau : couverte par les tests d'intégration (CSRF, sessions, échappement).
