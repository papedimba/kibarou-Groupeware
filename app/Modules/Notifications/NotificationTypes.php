<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

/**
 * Catalogue des types de notification. Chaque lot ajoute les siens ici.
 * Règle par défaut : email immédiat pour une demande à valider, une validation ou un rejet,
 * une convocation à une réunion, une alerte de sécurité ; récapitulatif quotidien pour le reste.
 */
final class NotificationTypes
{
    private const TYPES = [
        'security.password_changed' => ['label' => 'Mot de passe modifié', 'email' => 'immediate', 'browser' => false, 'mandatory' => true],
        'account.enabled' => ['label' => 'Compte réactivé', 'email' => 'immediate', 'browser' => false, 'mandatory' => false],
        'presence.changed' => ['label' => 'Statut modifié par un responsable', 'email' => 'digest', 'browser' => true, 'mandatory' => false],
        'chat.message' => ['label' => 'Nouveau message', 'email' => 'digest', 'browser' => true, 'mandatory' => false],
        'calendar.invited' => ['label' => 'Invitation à un évènement', 'email' => 'immediate', 'browser' => true, 'mandatory' => false],
        'calendar.updated' => ['label' => 'Évènement modifié', 'email' => 'immediate', 'browser' => true, 'mandatory' => false],
        'calendar.cancelled' => ['label' => 'Évènement annulé', 'email' => 'immediate', 'browser' => true, 'mandatory' => false],
        'calendar.reminder' => ['label' => 'Rappel d\'évènement', 'email' => 'off', 'browser' => true, 'mandatory' => false],
        'calendar.response' => ['label' => 'Réponse à mon invitation', 'email' => 'digest', 'browser' => false, 'mandatory' => false],
    ];

    /** @return array<string,array{label:string,email:string,browser:bool,mandatory:bool}> */
    public static function all(): array
    {
        return self::TYPES;
    }

    /** @return array{label:string,email:string,browser:bool,mandatory:bool} */
    public static function get(string $type): array
    {
        return self::TYPES[$type] ?? throw new \InvalidArgumentException("Type de notification inconnu : $type");
    }
}
