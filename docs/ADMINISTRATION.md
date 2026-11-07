# Guide d'administration

## Rôles livrés

| Rôle | Accès par défaut |
|---|---|
| Super administrateur | Tout, y compris les rôles et les autres super administrateurs. Au moins un doit toujours rester actif. |
| Administrateur | Administration : utilisateurs, services, fonctions, sessions, journal d'audit, paramètres. Ne peut ni attribuer ni modifier un super administrateur. |
| Directeur, Chef de service | Consultation des utilisateurs (servira à l'annuaire) ; droits métier ajoutés avec les prochains modules. |
| Collaborateur | Utilisateur standard. |

Les rôles sont personnalisables (**Administration › Rôles et permissions**, super administrateur uniquement) :
créez un rôle, cochez ses permissions, puis attribuez-le. Les rôles système ne peuvent pas être supprimés ;
un rôle encore attribué non plus. Un utilisateur peut cumuler plusieurs rôles : ses permissions s'additionnent.

## Créer un compte

**Administration › Utilisateurs › Nouvel utilisateur**, puis renseignez l'identité, le service, la fonction,
le responsable et au moins un rôle.
- **Par défaut**, un email d'activation (valable 72 h) est mis en file : l'utilisateur choisit lui-même son mot de passe.
- **Si l'email ne fonctionne pas encore**, saisissez un mot de passe temporaire : l'utilisateur devra le changer
  à sa première connexion. Transmettez-le de vive voix, jamais par messagerie.

## Départ ou suspension d'un collaborateur
Ouvrez sa fiche puis **Désactiver** : il ne peut plus se connecter et toutes ses sessions sont fermées immédiatement.
L'historique (audit, et plus tard ses messages et documents) est conservé. **Activer** rétablit l'accès.

## Mot de passe oublié
L'utilisateur clique sur « Mot de passe oublié ? » depuis la page de connexion. Un administrateur peut aussi
envoyer un lien depuis la fiche (**Lien de réinitialisation**).

## Compte bloqué
Après 5 échecs en 15 minutes, la connexion est refusée pendant 15 minutes. Le blocage se lève seul ; une
réinitialisation de mot de passe le lève immédiatement.

## Sessions
Chaque utilisateur voit ses sessions ouvertes dans **Mon profil** et peut fermer celles des autres appareils.
L'administrateur (permission `sessions.manage`) peut tout fermer depuis la fiche d'un utilisateur. Changer les rôles
d'un utilisateur ferme ses sessions, pour que ses nouveaux droits s'appliquent tout de suite.

## Surveillance
**Administration › Vue d'ensemble** : utilisateurs actifs, connectés, échecs de connexion sur 24 h, emails en échec,
et une alerte si la tâche CRON ne s'exécute plus. **Journal d'audit** : toutes les actions sensibles, filtrables par
préfixe (`auth.`, `user.`, `role.`…).
