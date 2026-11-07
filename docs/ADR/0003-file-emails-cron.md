# ADR 0003 — Emails et notifications en file d'attente, traités par CRON

- Statut : accepté (07/11/2026)

## Contexte
Un envoi SMTP pendant une requête HTTP ralentit l'utilisateur et fait échouer l'action si le SMTP est indisponible.
Pas de processus de fond permanent possible sur le mutualisé.

## Décision
Table `mail_queue` ; l'application ne fait qu'insérer. `bin/console cron:run` (toutes les 5 minutes) envoie par lots,
avec un verrou optimiste par ligne, des réessais à délai croissant et le statut `failed` après 5 tentatives.
Le module Notifications (lot 3) suivra le même principe.

## Conséquences
- (+) L'application reste fonctionnelle si l'envoi d'emails échoue.
- (−) Délai d'envoi jusqu'à 5 minutes (acceptable pour un intranet) ; le tableau de bord admin signale un CRON arrêté.
