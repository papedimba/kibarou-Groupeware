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
- **Si l'email ne fonctionne pas encore**, cliquez sur « Générer » (ou saisissez un mot de passe temporaire).
  Après l'enregistrement, un récapitulatif affiche l'adresse de connexion, l'identifiant et le mot de passe, avec
  « Copier » et « Imprimer ». Il résiste à une actualisation de la page, puis disparaît définitivement après
  « Terminé » ou au bout de 10 minutes. Remettez-le de vive voix ou sur papier, jamais par messagerie.
  L'utilisateur devra changer ce mot de passe à sa première connexion. Récapitulatif perdu : utilisez
  « Lien de réinitialisation » sur la fiche.
- Le rôle « Collaborateur » est coché par défaut.

Statuts affichés : **Actif**, **Pas encore connecté** (compte créé, jamais utilisé), **Désactivé**.

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

## Annuaire, organigramme et présence

- **Annuaire** (menu « Annuaire ») : tous les collaborateurs actifs, recherche instantanée par nom, fonction, service ou chantier.
- **Organigramme** : construit à partir des services (*Administration › Services*) : renseignez pour chaque service son **service parent** et son **responsable**. Les collaborateurs sans service apparaissent dans « Sans service ».
- **Responsable** d'une personne = son responsable hiérarchique (fiche utilisateur) **ou** le responsable de son service. Il voit son téléphone personnel, peut modifier son statut et dispose sur l'accueil du bloc « Mon équipe aujourd'hui » (mise à jour groupée).
- **Statut du jour** : « Au bureau », « Sur chantier : … », « En déplacement », « En congé », « Absent ». Sans date de fin, il s'efface le soir ; avec une date de fin (90 jours au plus), il reste jusqu'à ce jour-là.
- **Téléphones** : le professionnel est visible de tous ; le personnel seulement de la personne, de ses responsables et des administrateurs.
- **Photos** : chacun envoie la sienne depuis *Mon profil* ; un administrateur peut l'envoyer, la remplacer ou la retirer depuis la fiche utilisateur.

## Notifications

- **Cloche** (en haut à droite) : nombre de notifications non lues ; **Mes notifications** les liste, un clic ouvre la page concernée.
- **Préférences** (*Mon profil › Notifications*) : pour chaque type, email immédiat, récapitulatif quotidien (7 h) ou pas d'email, et alerte du navigateur. L'alerte « Mot de passe modifié » est toujours envoyée par email.
- **Alertes du navigateur** : sur PC et Android, quand l'intranet est ouvert (bouton « Activer les alertes du navigateur » dans les préférences). Sur iPhone : au lot 10, avec l'installation sur l'écran d'accueil.
- Envoyées aujourd'hui : mot de passe modifié, compte réactivé, statut modifié ou effacé par un responsable.

## Surveillance
L'**Accueil** d'un administrateur affiche des raccourcis et trois alertes : comptes pas encore connectés, échecs de connexion sur 24 h, emails en échec. **Administration › Vue d'ensemble** : utilisateurs actifs, connectés, échecs de connexion sur 24 h, emails en échec,
et une alerte si la tâche CRON ne s'exécute plus. **Journal d'audit** : toutes les actions sensibles, en français, filtrables par type d'action.
Une tentative de connexion refusée apparaît « Par : Inconnu » : le nom affiché dans « Concerne » est le compte visé, pas l'auteur.
