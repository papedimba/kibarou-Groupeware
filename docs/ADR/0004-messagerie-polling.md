# ADR 0004 — Messagerie instantanée par polling HTTP adaptatif

- Statut : accepté (07/11/2026), implémentation au lot 4

## Contexte
Pas de serveur WebSocket, de Node.js ni de processus permanent sur le mutualisé. Limites CPU et SQL de Likuid inconnues.

## Décision
Polling HTTP adaptatif sur un journal d'événements (`chat_events`) : le client ne demande que le delta depuis son dernier
identifiant d'événement, en une requête indexée ; intervalle de 3 à 30 s selon l'activité ; arrêt quand l'onglet est caché.
Le transport est isolé derrière une interface (`ChatTransport` en JS, `ChatEventPublisher` en PHP) pour pouvoir passer
plus tard à un service temps réel externe sans réécrire le module.

Le long polling a été écarté : il bloquerait un processus PHP par utilisateur connecté, ce qui consomme vite les
processus disponibles sur un mutualisé.

## Conséquences
- (+) Compatible avec n'importe quel hébergement PHP.
- (−) Latence de 3 à 30 s et charge proportionnelle au nombre d'onglets ouverts : dimensionnement à valider avec les limites Likuid.
