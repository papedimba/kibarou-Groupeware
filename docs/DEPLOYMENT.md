# Déploiement sur l'hébergement mutualisé Likuid

Cible : `https://gw.kibarouconstruction.com`. Les points marqués **[à vérifier]** dépendent du plan Likuid
(voir PLAN.md §1).

## 1. Prérequis

- PHP ≥ 8.2 avec `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `json` (et `intl` recommandé) **[à vérifier]**
- MySQL 8+ ou MariaDB 10.4+ : une base vide en `utf8mb4_unicode_ci` et un utilisateur dédié avec les droits sur cette base uniquement
- Apache avec `mod_rewrite` et `.htaccess` autorisés **[à vérifier]**
- Certificat TLS actif sur le sous-domaine (Let's Encrypt via le panneau)
- Une boîte email d'envoi (SMTP) pour `noreply@kibarouconstruction.com`

## 2. Préparer le paquet (sur un poste disposant de PHP et Composer)

```bash
git clone https://github.com/papedimba/kibarou-Groupeware.git && cd kibarou-Groupeware
composer install --no-dev --optimize-autoloader
```
Le dossier `vendor/` doit être livré avec l'application (aucune commande n'est nécessaire sur le serveur).

## 3. Envoi des fichiers

### Option A — racine web modifiable (recommandée)
1. Envoyer tout le projet (y compris `vendor/`) dans un dossier **hors** de la racine publique, ex. `~/kibarou-groupware/`.
2. Dans le panneau Likuid, faire pointer le sous-domaine `gw` vers `~/kibarou-groupware/public`.

### Option B — racine web imposée
Envoyer le projet dans la racine du sous-domaine. Le `.htaccess` racine redirige toutes les requêtes vers `public/`
et rien d'autre n'est servi. Vérifier après l'envoi que `https://gw.kibarouconstruction.com/.env` et
`/composer.json` renvoient bien une erreur 404 ou 403.

### Droits
`storage/` et ses sous-dossiers doivent être accessibles en écriture par PHP (755 pour les dossiers, 644 pour les fichiers ;
pas de 777). Le fichier `.env` : 600 ou 640.

## 4. Configuration

Copier `.env.example` en `.env` (à la racine du projet, jamais dans `public/`) et renseigner :
`APP_URL`, `DB_*`, `MAIL_*`, `APP_TIMEZONE`, et laisser `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE=true`.

## 5. Installation de la base et du premier compte

### Avec SSH
```bash
php bin/console migrate
php bin/console admin:create      # interactif : le mot de passe n'apparaît ni à l'écran ni dans l'historique
```

### Sans SSH (FTP uniquement)
1. Générer un jeton aléatoire d'au moins 32 caractères (ex. sur un poste : `php -r "echo bin2hex(random_bytes(24));"`)
   et le placer dans `.env` : `INSTALL_TOKEN=...`
2. Ouvrir `https://gw.kibarouconstruction.com/install?token=<jeton>` : les migrations s'exécutent, puis le formulaire
   de création du super administrateur s'affiche.
3. Après création, l'assistant répond 404. **Supprimer la ligne `INSTALL_TOKEN` du `.env`.**

## 6. Tâche CRON

Une seule entrée, toutes les 5 minutes **[à vérifier : syntaxe et chemin de PHP dans le panneau Likuid]** :
```
*/5 * * * * /usr/bin/php /home/<compte>/kibarou-groupware/bin/console cron:run >/dev/null 2>&1
```
Elle envoie les emails en file et applique la politique de conservation. Si elle ne tourne pas depuis plus de
30 minutes, le tableau de bord d'administration affiche une alerte.

## 7. Mises à jour

1. **Sauvegarder la base et les fichiers** (§8).
2. Préparer le nouveau paquet (`composer install --no-dev`), l'envoyer en écrasant `app/`, `config/`, `database/`,
   `public/`, `routes/`, `vendor/`, `bin/` (ne jamais écraser `.env` ni `storage/`).
3. Appliquer les migrations : `php bin/console migrate` (SSH), ou temporairement via CRON
   (`php .../bin/console migrate` une fois). L'assistant `/install` n'applique les migrations que lors de l'installation initiale.

## 8. Sauvegarde et restauration

**À sauvegarder** : la base de données, `storage/private/` (fichiers des utilisateurs à partir du lot 2) et `.env`.

- Base : sauvegardes automatiques de Likuid **[à vérifier : fréquence et durée de conservation]**, plus un export
  hebdomadaire `mysqldump --single-transaction --routines <base> | gzip` (CRON) déposé **hors du dossier web**
  et rapatrié hors serveur. Un script de sauvegarde chiffrée sera fourni au lot 10.
- Restauration testée au moins une fois par trimestre :
  1. Mettre l'application en maintenance (renommer temporairement `public/index.php`).
  2. `gunzip < sauvegarde.sql.gz | mysql <base>` (ou import phpMyAdmin pour une petite base).
  3. Restaurer `storage/private/` et `.env`.
  4. `php bin/console migrate` si la sauvegarde est antérieure à une mise à jour.
  5. Remettre `index.php` et vérifier la connexion d'un administrateur.

## 9. Checklist de mise en production

- [ ] PHP ≥ 8.2 et extensions vérifiés (`phpinfo()` temporaire, **supprimé** ensuite)
- [ ] Limites d'envoi : `upload_max_filesize` ≥ 6M et `post_max_size` ≥ 8M (fichier `public/.user.ini` fourni ; sinon à régler dans le panneau Likuid)
- [ ] Extension GD avec prise en charge WebP active (photos de profil) ; sans elle, l'envoi de photo est refusé avec un message clair
- [ ] Limites Likuid obtenues par écrit (CPU, processus, connexions SQL, requêtes/heure) et reportées dans PLAN.md
- [ ] HTTPS actif, redirection HTTP → HTTPS effective, HSTS présent
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE=true`
- [ ] `/.env`, `/composer.json`, `/storage/logs/`, `/database/` inaccessibles depuis le web (403/404)
- [ ] Base créée en utf8mb4 avec un utilisateur aux droits limités à cette base
- [ ] Premier super administrateur créé ; `INSTALL_TOKEN` supprimé du `.env`
- [ ] CRON configuré ; « dernière exécution » visible et récente dans l'administration
- [ ] Email de test reçu (réinitialisation de mot de passe d'un compte de test)
- [ ] Services, fonctions et comptes réels créés (aucune donnée de démonstration : `seed:demo` est refusé en production)
- [ ] Sauvegarde automatique vérifiée et restauration testée
- [ ] `composer audit` sans vulnérabilité connue
- [ ] Test de fumée Playwright exécuté contre l'environnement de recette

## 10. Développement local

```bash
composer install
cp .env.example .env    # APP_ENV=local, APP_DEBUG=true, SESSION_SECURE=false, DB_* locaux
php bin/console migrate && php bin/console admin:create && php bin/console seed:demo
php -S 127.0.0.1:8080 -t public tests/e2e/router.php
composer test           # nécessite une base de test (voir phpunit.xml : TEST_DB_*)
```
