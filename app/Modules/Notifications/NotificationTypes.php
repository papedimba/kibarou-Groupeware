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
