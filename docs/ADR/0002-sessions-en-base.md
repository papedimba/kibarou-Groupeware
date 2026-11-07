# ADR 0002 — Sessions stockées en base de données

- Statut : accepté (07/11/2026)

## Contexte
Il faut pouvoir lister les sessions d'un utilisateur, les révoquer (désactivation, changement de rôles ou de mot de passe)
et ne pas dépendre du répertoire de sessions PHP partagé du mutualisé.

## Décision
Classe `Session` maison : cookie contenant un jeton aléatoire de 256 bits, table `sessions` indexée par son empreinte
SHA-256, données en JSON, expiration d'inactivité et absolue contrôlée en SQL, écriture limitée à une fois par minute
quand rien ne change.

## Conséquences
- (+) Révocation immédiate, liste des appareils, aucune fuite exploitable si la table est lue.
- (+) Testable sans les fonctions `session_*` de PHP.
- (−) Une écriture SQL par minute et par utilisateur actif : négligeable pour 50 utilisateurs.
