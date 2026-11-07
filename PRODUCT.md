# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
Les ~50 collaborateurs de Kibarou Construction (entreprise de construction), répartis entre :
- **le bureau** (direction, RH, comptabilité, études, informatique), sur PC ;
- **les chantiers** (conducteurs de travaux, chefs de chantier, agents), sur smartphone, souvent avec une connexion faible ou instable.

Leur aisance numérique est **hétérogène** : les cadres sont à l'aise ; une partie des agents de terrain ou des collaborateurs plus âgés utilisent peu d'outils numériques. Les administrateurs (super administrateur, administrateurs RH/DSI) gèrent les comptes, les services et les droits.

## Product Purpose
Intranet privé (`gw.kibarouconstruction.com`) qui réunit la communication et le travail collaboratif de l'entreprise : messagerie instantanée, calendrier, tâches, projets, réunions, documents, annonces, demandes avec circuit de validation (workflows), annuaire et notifications. Réussite : les échanges et les validations internes passent par l'intranet au lieu de canaux dispersés, y compris pour les équipes de chantier.

## Positioning
Outil interne propre à Kibarou Construction, hébergé sur son mutualisé, organisé selon ses services et ses rôles (direction, chefs de service, collaborateurs), en français, et utilisable depuis un chantier sur téléphone.

## Operating Context
- Utilisation alternée bureau / chantier ; sur chantier : écran de téléphone, extérieur, connexion faible, mains souvent occupées.
- Hébergement mutualisé Likuid : pas de temps réel natif ; la messagerie fonctionne par interrogation périodique, avec quelques secondes de latence.
- Les emails partent par une file traitée toutes les 5 minutes.

## Capabilities and Constraints
- Lot 1 livré : authentification, administration (utilisateurs, services, fonctions, rôles et permissions, sessions, journal d'audit), tableau de bord.
- Lots suivants : annuaire, notifications, messagerie, calendrier et réunions, tâches et projets, documents, annonces, workflows (voir PLAN.md).
- Langue : français uniquement.
- Stack déjà en place : PHP natif, rendu serveur, Bootstrap 5, Lucide, JavaScript natif, CSP stricte (aucun script ni style inline).
- Aucun module de type site public, landing page, multisite ou éditeur visuel n'est prévu (confirmé par l'utilisateur).

## Brand Commitments
- Nom : **Kibarou Groupware**, pour **Kibarou Construction**.
- Le cahier des charges fixe la direction : bleu nuit, blanc, gris clair, accent bleu, accent orange pour les alertes, typographie Inter, icônes Lucide.
- **Le logo et la charte officiels seront fournis par l'utilisateur** : en attendant, la marque « K » est provisoire et doit pouvoir être remplacée.

## Evidence on Hand
- Aucune donnée réelle de l'entreprise dans le dépôt : les comptes et services de démonstration (`database/seeds/demo.php`) sont fictifs et réservés aux tests.
- Aucun logo officiel pour l'instant.

## Product Principles
1. **Évident pour un non-technicien** : vocabulaire métier simple, une action principale par écran, confirmations claires.
2. **Le téléphone de chantier compte autant que le PC** : chaque écran utile sur le terrain fonctionne au pouce, avec une connexion faible.
3. **Les droits et la confiance sont visibles** : chacun voit ce qu'il peut faire ; les actions sensibles sont confirmées et tracées.
4. **Sobre et rapide** plutôt que décoratif : un intranet utilisé tous les jours.

## Accessibility & Inclusion
Utilisateurs peu habitués au numérique et usage en extérieur : contrastes élevés (WCAG 2.1 AA au minimum), cibles tactiles d'au moins 44 px, textes lisibles sans zoom, navigation au clavier.
