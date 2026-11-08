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

## Messagerie

- Permission **Utiliser la messagerie** (`chat.use`), accordée par défaut aux rôles livrés ; le menu *Messagerie* affiche le nombre de messages non lus.
- **Écrire** depuis une fiche de l'annuaire ouvre la conversation privée ; *Nouvelle conversation* permet aussi de créer un groupe (nom obligatoire, 51 membres au plus).
- Dans un groupe, tout membre peut ajouter des personnes, le renommer ou le quitter. Les nouveaux venus ne voient pas les messages antérieurs.
- Les conversations sont privées : un administrateur ne peut pas les lire. Le journal d'audit enregistre la création des groupes, les ajouts et les départs, jamais le contenu.
- Un compte désactivé ne peut plus recevoir de messages ; ses messages passés restent visibles des autres membres.

### Calendrier (lot 5a)
- Menu **Calendrier** : semaine, mois, liste de **mes** évènements ; « Nouvel évènement » (invités, service entier, rappel, répétition, privé).
- Les invités répondent « J'y serai », « Peut-être » ou « Je n'y serai pas » ; l'organisateur seul modifie ou annule (« cette fois » ou « celle-ci et les suivantes » pour une série). Une série dure un an au plus ; on la prolonge en changeant sa date de fin.
- **Disponibilités** : jusqu'à dix personnes sur sept jours, « Occupé » et congés, sans le détail des évènements. Personne ne peut ouvrir l'agenda d'un collègue.
- **Rappels** : par alerte du navigateur (email désactivable ou activable dans *Mon profil › Notifications*) ; ils dépendent de la tâche planifiée.
- **Agenda sur le téléphone** (*Mon profil › Agenda sur mon téléphone*) : lien secret à coller dans Outlook, Google Agenda ou l'iPhone. En cas de lien perdu ou partagé, en créer un nouveau.

### Salons et salons de service (lot 4c)
- **Salons** (*Messagerie › Salons*) : ouverts à toute l'entreprise, typiquement un par chantier ou par thème. Chacun les rejoint ou les quitte librement et lit tout l'historique en arrivant. Les alertes y sont coupées par défaut ; chacun peut les activer par l'interrupteur « Alertes » d'une conversation.
- **Créer un salon** : administrateurs, directeurs et chefs de service (permission « Gérer les salons »). **Modifier, archiver, rouvrir** : administrateurs et directeurs sur tous les salons ; un chef de service sur ceux qu'il a créés. Un salon archivé reste lisible (et peut être rejoint) mais n'accepte plus de messages ; il n'est jamais supprimé.
- **Salons de service** : un par service actif, automatique. Les membres suivent l'annuaire (changer le service d'une personne la fait changer de salon, désactiver un compte l'en retire) ; le salon prend le nom du service et est archivé si le service est désactivé. La tâche planifiée rattrape toute différence toutes les 5 minutes.
- **Accusés de lecture** : « Vu » en conversation privée, « Vu par N » (avec les noms) en groupe et en salon.
- **Recherche** : champ « Rechercher dans mes messages » en haut de la liste ; texte et noms de fichiers, dans ce que chacun a le droit de lire.

### Pièces jointes, réponses, réactions (lot 4b)
- Chacun peut joindre des photos, des fichiers Word (.docx), Excel (.xlsx) ou PDF, jusqu'à 10 par message et 6 Mo par fichier. Les photos sont réduites automatiquement. Les anciens formats .doc et .xls sont refusés : il faut enregistrer le fichier en .docx ou .xlsx.
- Répondre, réagir, modifier ou supprimer : bouton « … » au survol sur PC, appui long sur téléphone. Modifier et supprimer ne sont possibles que sur ses propres messages, pendant 15 minutes.
- **Espace disque** : *Vue d'ensemble* affiche la place occupée par les pièces jointes, avec une alerte au-delà du seuil `CHAT_STORAGE_WARN_MB` (2 000 Mo par défaut). Les fichiers ne sont pas purgés automatiquement : ils disparaissent avec la suppression de leur message.

## Surveillance
L'**Accueil** d'un administrateur affiche des raccourcis et trois alertes : comptes pas encore connectés, échecs de connexion sur 24 h, emails en échec. **Administration › Vue d'ensemble** : utilisateurs actifs, connectés, échecs de connexion sur 24 h, emails en échec,
et une alerte si la tâche CRON ne s'exécute plus. **Journal d'audit** : toutes les actions sensibles, en français, filtrables par type d'action.
Une tentative de connexion refusée apparaît « Par : Inconnu » : le nom affiché dans « Concerne » est le compte visé, pas l'auteur.
