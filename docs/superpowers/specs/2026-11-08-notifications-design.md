# Lot 3 — Notifications : conception

Statut : rédigé le 08/11/2026, en attente de relecture et d'approbation.
Références : cahier des charges, module 8 ; `PRODUCT.md` ; `PLAN.md` (lot 3) ; file d'emails et tâche CRON du lot 1.

## 1. Intention

Prévenir chaque collaborateur de ce qui le concerne, au bon endroit et sans le noyer : dans l'intranet (cloche), dans le navigateur quand l'intranet est ouvert, et par email — immédiatement quand une action ou la sécurité l'exige, sinon dans un récapitulatif quotidien. Le lot 3 livre le **socle** ; chaque lot suivant y branche ses propres types (message, réunion, échéance, tâche, annonce, demande à valider, validation/rejet).

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Une fonction unique, appelée par les modules, pour notifier une personne.
- Cloche avec compteur de non-lues, page « Mes notifications », marquage lu / tout lu.
- Alerte navigateur (API `Notification`) quand l'intranet est ouvert et que la personne l'a autorisée.
- Emails : immédiats pour les types qui demandent une action ou touchent à la sécurité ; récapitulatif quotidien à 7 h (heure de l'entreprise) pour le reste ; réglable type par type.
- Préférences par type et par canal.
- 3 notifications branchées dès le lot 3 (§5.6).

**Non-objectifs**
- Notifications « push » navigateur fermé (Web Push, clés VAPID, service worker) : **lot 10**, avec l'application installable. Le modèle de données du lot 3 doit le permettre sans refonte.
- Notifications des modules futurs (lots 4 à 9) : chaque lot ajoute ses types au catalogue.
- SMS, WhatsApp.
- Envoi groupé par un administrateur (« message à tous ») : ce sont les annonces du lot 8.

**Anti-objectifs**
- Un email bloquant ou ralentissant une action (envoi toujours différé, par la file existante).
- Une alerte de sécurité qu'on peut désactiver.
- Un flot d'emails : sans réglage, le reste passe par le récapitulatif.
- Une session qui ne s'expire plus jamais parce qu'un onglet ouvert interroge le serveur en permanence.
- Une notification qui révèle à quelqu'un une information qu'il n'a pas le droit de voir.

## 3. Contraintes

- Mutualisé : pas de processus permanent ; tout envoi par la tâche CRON existante (`cron:run`, toutes les 5 min) et la `mail_queue` existante (réessais, verrou, alerte de CRON arrêté).
- Structure existante, requêtes préparées, `e()`, CSP stricte (aucun script inline), français, WCAG AA, cibles 44 px.
- Fuseau : « 7 h » et « aujourd'hui » dans `APP_TIMEZONE` ; dates stockées en UTC.
- Interrogation du serveur : 60 s, arrêtée quand l'onglet est caché (`document.visibilityState`), une requête SQL indexée par appel.

## 4. Approches envisagées

| Approche | Avantages | Inconvénients | Décision |
|---|---|---|---|
| **1. Centrale d'envoi appelée directement par les modules** | Simple à lire et à tester, une ligne par module, réutilise file d'emails et CRON | Les modules connaissent la centrale | **Retenue** (choix de l'utilisateur) |
| 2. Système d'évènements (abonnements) | Découplage | Plus de mécanique, plus difficile à suivre pour une petite équipe | Écartée |
| 3. Déduire les notifications du journal d'audit | Pas de nouvelle écriture | Le journal ne sait pas qui prévenir ; mélange sécurité et information | Écartée |

## 5. Ce qui a été décidé

### 5.1 Données (migration 0004)

`notifications`

| Colonne | Rôle |
|---|---|
| `id` BIGINT PK auto | sert aussi de curseur pour l'interrogation |
| `user_id` FK users CASCADE | destinataire |
| `type` VARCHAR(50) | clé du catalogue (§5.2) |
| `title` VARCHAR(190) | texte court, déjà rédigé en français |
| `body` VARCHAR(500) NULL | précision facultative |
| `url` VARCHAR(255) NULL | chemin interne (commence par `/`) ouvert au clic |
| `actor_id` FK users SET NULL, NULL | auteur éventuel |
| `created_at` DATETIME UTC | |
| `read_at` DATETIME NULL | |
| `email_status` ENUM('none','queued','digest_pending','digested') | suivi email |

Index : `(user_id, read_at, id)` pour le compteur et la liste ; `(email_status, user_id)` pour le récapitulatif.

`notification_preferences` : `user_id`, `type`, `email` ENUM('immediate','digest','off'), `browser` TINYINT ; clé primaire `(user_id, type)`. **Absence de ligne = valeurs par défaut du catalogue** (aucune ligne créée tant que la personne ne change rien).

### 5.2 Catalogue des types

Dans le code (`NotificationTypes`), une entrée par type : libellé affiché dans les préférences, email par défaut (`immediate` / `digest` / `off`), navigateur par défaut (oui/non), et `mandatory` (email immédiat imposé, préférence non modifiable). Le lot 3 déclare :

| Type | Libellé | Email par défaut | Navigateur | Obligatoire |
|---|---|---|---|---|
| `security.password_changed` | Mot de passe modifié | immédiat | non | **oui** |
| `account.enabled` | Compte réactivé | immédiat | non | non |
| `presence.changed` | Statut modifié par un responsable | récapitulatif | oui | non |

Règle pour les lots suivants (documentée dans le catalogue) : immédiat par défaut pour « demande à valider », « validation / rejet », « convocation à une réunion » ; récapitulatif pour le reste.

### 5.3 Centrale d'envoi

`Notifier::notify(int $userId, string $type, string $title, ?string $url = null, ?string $body = null, ?int $actorId = null): void`
- Type inconnu → exception de programmation (pas de notification silencieusement mal typée).
- Destinataire inactif ou supprimé → rien n'est créé.
- Insère la notification ; selon la préférence effective : `immediate` → ajoute un email à la `mail_queue` (sujet = titre, corps = titre + précision + lien absolu `APP_URL` + rappel « Gérer mes notifications ») et `email_status = queued` ; `digest` → `digest_pending` ; `off` → `none`.
- Ne déclenche jamais d'envoi SMTP pendant la requête.
- `url` doit être un chemin interne ; tout autre valeur est ignorée (pas de lien externe dans une notification).
- Le contenu est rédigé par le module appelant, qui est responsable de ne mettre dans le titre que ce que le destinataire a le droit de savoir.

### 5.4 Interface

- **Cloche** dans l'en-tête (toutes les pages connectées) : icône + pastille du nombre de non-lues (masquée à 0, « 99+ » au-delà), lien vers `/notifications`, nom accessible « Notifications, N non lues ».
- **Page `/notifications`** : liste paginée (25), plus récentes d'abord ; non-lues mises en évidence (gras + point + texte « non lue ») ; chaque entrée : titre, précision, date relative simple (« il y a 5 min », « hier », puis la date) ; clic → `POST /notifications/{id}/open` qui marque lue et redirige vers `url` (ou reste sur la page) ; bouton « Tout marquer comme lu ». État vide : « Aucune notification pour l'instant. »
- Une personne ne voit et ne modifie que **ses** notifications (contrôle serveur ; 404 sinon).
- **Préférences** `/profile/notifications` (lien depuis « Mon profil » et la page des notifications) : un tableau type × {Email : Immédiat / Récapitulatif quotidien / Aucun ; Alerte navigateur : oui/non} ; les types obligatoires affichés non modifiables avec la mention « Alerte de sécurité, toujours envoyée par email ». Bouton « Activer les alertes du navigateur » qui demande l'autorisation (`Notification.requestPermission`) ; message clair si refusée ou non prise en charge.

### 5.5 Interrogation et alertes navigateur

- `GET /api/notifications?since=<dernier id connu>` (authentifié, JSON) : `{"unread": N, "items": [{"id", "title", "url", "alert"}]}` — les notifications non lues plus récentes que `since`, 10 au plus ; `alert` vaut vrai si la préférence navigateur du type est active.
- Le script de page appelle ce point toutes les **60 s**, uniquement quand l'onglet est visible, reprend immédiatement au retour sur l'onglet ; met à jour la pastille ; pour chaque item `alert`, affiche une notification système (titre = « Kibarou Groupware », corps = titre de la notification) si l'autorisation est accordée ; clic sur la notification système → ouvre l'URL.
- **Les appels d'interrogation ne prolongent pas la session** : ils ne mettent pas à jour `last_activity_at` (sinon un poste laissé ouvert resterait connecté jusqu'à l'expiration absolue de 12 h, et la pastille « en ligne » serait faussée). Une session expirée → 401 JSON → le script s'arrête sans boucler.
- Le premier chargement de page fournit le dernier id connu (pas d'alerte pour les notifications anciennes).

### 5.6 Notifications branchées au lot 3

| Évènement (code existant) | Destinataire | Type | Titre |
|---|---|---|---|
| Changement de son propre mot de passe (`AuthService::changePassword`) et réinitialisation (`PasswordResetService::reset`) | la personne | `security.password_changed` | « Votre mot de passe a été modifié » ; précision : « Si vous n'êtes pas à l'origine de ce changement, contactez immédiatement un administrateur. » |
| Réactivation d'un compte (`UserService::setStatus` vers actif) | la personne | `account.enabled` | « Votre compte a été réactivé » |
| Statut modifié par quelqu'un d'autre (`PresenceService`, y compris mise à jour groupée) | la personne | `presence.changed` | « Votre statut a été modifié par {Prénom Nom} : {libellé du statut} » ; lien `/profile` |

Pour la réinitialisation par lien, la personne n'est pas connectée : seul l'email lui parvient immédiatement ; la notification l'attend dans l'intranet.

### 5.7 Tâche CRON

Dans `cron:run`, avant l'envoi de la file :
- **Récapitulatif quotidien** : si l'heure locale est ≥ 07:00 et que le récapitulatif du jour local n'a pas encore été produit (clé `settings.digest_last_date`), pour chaque personne ayant des notifications `digest_pending` **non lues**, un seul email « Vos notifications du {date} » listant leurs titres (20 au plus, puis « et N autres ») avec un lien vers `/notifications` ; ces notifications passent à `digested`. Les `digest_pending` déjà lues passent à `digested` sans email. Production protégée contre la double exécution (verrou CRON existant + mise à jour de la clé dans la même transaction).
- **Nettoyage** (dans `Maintenance`) : notifications lues depuis plus de 90 jours, et toutes celles de plus de 365 jours.

## 6. Laissé au constructeur

- Noms des classes et des vues dans la structure existante (`app/Modules/Notifications/`).
- Libellés exacts des dates relatives.
- Gabarit texte des emails (texte brut, comme les emails existants).
- Découpage en tâches, données de démonstration.

## 7. Critères de réussite

- Un changement de mot de passe produit toujours un email immédiat, même si la personne a tout désactivé (test).
- Une notification de type `digest` n'apparaît qu'une fois dans un récapitulatif, et pas si elle a été lue (tests avec heure simulée).
- Personne ne lit ni ne marque les notifications d'autrui (test).
- L'interrogation ne prolonge pas la session et renvoie 401 une fois la session expirée (tests).
- La cloche se met à jour sans rechargement et l'alerte navigateur s'affiche (test de fumée Playwright avec autorisation accordée).
- Tous les tests existants restent verts ; détecteur Impeccable sans avertissement sur les nouvelles pages.
