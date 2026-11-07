# Sécurité

Référentiel : OWASP Top 10 (2021) et OWASP ASVS niveau 2, adaptés à un intranet de 50 utilisateurs.

## Mesures implémentées (lot 1)

| Risque | Mesure | Où |
|---|---|---|
| Vol de mots de passe | `password_hash()` (bcrypt, coût par défaut), `password_verify()`, nouveau hachage automatique à la connexion si l'algorithme change ; 10 caractères minimum, au moins une lettre et un chiffre, pas l'identifiant ; 72 octets maximum (limite bcrypt) | `AuthService`, `PasswordPolicy` |
| Force brute | 5 échecs par email ou 20 par IP en 15 minutes → refus (429), même avec le bon mot de passe ; temps de réponse homogène (hash factice) | `AuthService::guardThrottle` |
| Énumération de comptes | Message unique « Email ou mot de passe incorrect » ; réponse identique au « mot de passe oublié », que le compte existe ou non | `AuthService`, `AuthController` |
| Fixation et vol de session | Régénération de l'identifiant à la connexion ; cookie `HttpOnly`, `Secure`, `SameSite=Lax` ; seule l'empreinte SHA-256 du jeton est stockée ; expiration après 2 h d'inactivité et 12 h maximum ; sessions fermées à la désactivation, au changement de rôles, au changement de mot de passe | `Session`, `UserService` |
| CSRF | Jeton de 256 bits par session, vérifié en temps constant sur **toute** requête non-GET (champ `_csrf` ou en-tête `X-CSRF-Token`) | `Middleware::csrf` |
| XSS | Échappement systématique `e()` (ENT_QUOTES + ENT_SUBSTITUTE) ; CSP stricte sans `unsafe-inline` ni `unsafe-eval` ; aucun script ou style inline | Vues, `SecurityHeaders` |
| Injection SQL | Requêtes préparées PDO, émulation désactivée ; les recherches `LIKE` échappent `%` et `_` | `Database`, repositories |
| Contrôle d'accès | Permissions vérifiées côté serveur pour chaque route (`perm:`) ; règles par ressource dans les services (un administrateur ne peut ni attribuer `super_admin` ni modifier un super administrateur ; personne ne se désactive soi-même ; au moins un super administrateur actif) ; paramètres d'URL `{id}` limités aux entiers ; un identifiant n'est jamais une preuve d'autorisation | `routes/web.php`, `UserService` |
| Redirection ouverte | Après connexion, seuls les chemins internes sont acceptés | `AuthController::login` |
| Réinitialisation | Jeton aléatoire de 256 bits, empreinte seule en base, usage unique (`FOR UPDATE`), 60 min (72 h pour une activation), 3 demandes par heure, toutes les sessions fermées après réinitialisation | `PasswordResetService` |
| Divulgation d'informations | Erreurs 500 génériques avec référence ; détails uniquement dans `storage/logs` ; `display_errors=0` ; `expose_php=0` | `ErrorHandler`, `public/index.php` |
| Exposition de fichiers | Seul `public/` est servi ; `.htaccess` racine qui redirige vers `public/` ; `Require all denied` dans `app/`, `config/`, `storage/`… ; fichiers cachés, `.env`, `.sql`, `.log` refusés | `.htaccess` |
| Clickjacking, détournement MIME | `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy`, HSTS, `Cache-Control: no-store` | `SecurityHeaders` |
| Traçabilité | Journal d'audit : connexions (réussies, échouées, bloquées), déconnexions, création ou modification de comptes et de rôles, activations, révocations, réinitialisations | `AuditLogger` |
| Mot de passe temporaire | Jamais journalisé ni stocké en clair : conservé 10 minutes au plus dans la session, chiffré (libsodium secretbox) avec une clé dérivée du jeton du cookie, que la base ne contient pas ; effacé par « Terminé » | `Session::seal`, `UsersController::handover` |
| Installation | Pas de mot de passe dans le code ; premier super administrateur créé en CLI interactif, ou par l'assistant web protégé par `INSTALL_TOKEN` (32 caractères minimum), qui répond 404 dès qu'un super administrateur existe | `Installer` |

## Politique de conservation (appliquée par `cron:run`, toutes les 5 minutes)

| Donnée | Durée |
|---|---|
| Sessions | 2 h d'inactivité, 12 h maximum |
| Tentatives de connexion | 90 jours |
| Liens de réinitialisation | 1 jour après expiration ou utilisation |
| Emails envoyés ou en échec | 30 jours |
| Journal d'audit | 730 jours (`AUDIT_RETENTION_DAYS`) |
| Journaux techniques (`storage/logs`) | Rotation quotidienne ; purge manuelle ou par l'hébergeur (à automatiser au lot 10) |

## Uploads (à partir du lot 2)
Les fichiers iront sous `storage/private/`, hors de la racine web, avec un nom aléatoire et sans extension exécutable.
Contrôles : liste blanche d'extensions **et** de types MIME détectés par `finfo` (jamais celui envoyé par le navigateur),
taille maximale, nom d'origine assaini et stocké en base uniquement, réencodage des images, puis vérification de
l'autorisation à chaque téléchargement (`Content-Disposition: attachment`, `nosniff`).

## Points ouverts / recommandations
- **2FA (TOTP)** pour les super administrateurs : recommandée, prévue au lot 10.
- **Adresse IP derrière un proxy** : seul `REMOTE_ADDR` est pris en compte. Si Likuid place un reverse proxy devant
  PHP, la limitation par IP doit lire l'en-tête du proxy de confiance (à vérifier lors du déploiement).
- **PHPStan** à ajouter en CI (non installable dans l'environnement de développement actuel).
- Sauvegardes chiffrées hors serveur : voir `docs/DEPLOYMENT.md`.
- Revue de dépendances : `composer audit` avant chaque mise en production.
