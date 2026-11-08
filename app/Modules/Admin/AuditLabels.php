<?php

declare(strict_types=1);

namespace App\Modules\Admin;

/** Traduit les codes du journal d'audit en français lisible par un administrateur non technique. */
final class AuditLabels
{
    private const LABELS = [
        'auth.login' => 'Connexion',
        'auth.login_failed' => 'Échec de connexion',
        'auth.login_disabled' => 'Connexion refusée (compte désactivé)',
        'auth.throttled' => 'Connexion bloquée (trop de tentatives)',
        'auth.logout' => 'Déconnexion',
        'auth.password_changed' => 'Mot de passe modifié',
        'auth.reset_requested' => 'Réinitialisation demandée',
        'auth.reset_completed' => 'Mot de passe réinitialisé',
        'auth.invitation_sent' => 'Lien d\'activation envoyé',
        'install.super_admin_created' => 'Installation : super administrateur créé',
        'profile.updated' => 'Profil modifié',
        'profile.session_revoked' => 'Session fermée par l\'utilisateur',
        'user.created' => 'Compte créé',
        'user.updated' => 'Compte modifié',
        'user.enabled' => 'Compte activé',
        'user.disabled' => 'Compte désactivé',
        'user.reset_link_sent' => 'Lien de réinitialisation envoyé',
        'user.sessions_revoked' => 'Sessions fermées',
        'role.saved' => 'Rôle enregistré',
        'role.deleted' => 'Rôle supprimé',
        'department.created' => 'Service créé',
        'department.updated' => 'Service modifié',
        'position.created' => 'Fonction créée',
        'position.updated' => 'Fonction modifiée',
        'presence.updated' => 'Statut modifié',
        'chat.group_created' => 'Groupe de messagerie créé',
        'chat.member_added' => 'Membre ajouté à un groupe',
        'chat.member_left' => 'Groupe de messagerie quitté',
        'chat.message_deleted' => 'Message de messagerie supprimé',
        'chat.channel_created' => 'Salon créé',
        'chat.channel_updated' => 'Salon modifié',
        'chat.channel_archived' => 'Salon archivé',
        'calendar.event_cancelled' => 'Évènement annulé',
        'calendar.feed_generated' => 'Lien d\'abonnement au calendrier créé',
        'profile.photo_updated' => 'Photo modifiée',
        'user.photo_removed' => 'Photo retirée',
    ];

    private const ALERTS = ['auth.login_failed', 'auth.login_disabled', 'auth.throttled', 'user.disabled', 'user.sessions_revoked'];

    private const DETAIL_KEYS = ['email' => 'Email', 'count' => 'Nombre', 'changes' => 'Champs modifiés', 'name' => 'Nom', 'status' => 'Statut'];

    private const FIELDS = ['email' => 'Email', 'first_name' => 'Prénom', 'last_name' => 'Nom', 'phone' => 'Téléphone professionnel', 'phone_personal' => 'Téléphone personnel',
        'department_id' => 'Service', 'position_id' => 'Fonction', 'manager_id' => 'Responsable'];

    /** Pour ces actions, user_id désigne le compte visé, pas l'auteur. */
    private const ANONYMOUS = ['auth.login_failed', 'auth.login_disabled', 'auth.throttled', 'auth.reset_requested'];
    /** Envoyées automatiquement ; l'auteur réel figure sur la ligne « Compte créé » ou « Lien envoyé ». */
    private const SYSTEM = ['auth.invitation_sent'];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    public static function isAlert(string $action): bool
    {
        return in_array($action, self::ALERTS, true);
    }

    /** Auteur affiché dans la colonne « Par ». */
    public static function actor(string $action, ?string $userName): string
    {
        return match (true) {
            in_array($action, self::ANONYMOUS, true) => 'Inconnu',
            in_array($action, self::SYSTEM, true), $userName === null => 'Système',
            default => $userName,
        };
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        $labels = self::LABELS;
        asort($labels);
        return $labels;
    }

    /**
     * Détails JSON en paires libellé / valeur ; les listes d'identifiants (rôles, permissions) sont omises.
     * @return list<array{0:string,1:string}>
     */
    public static function details(?string $json): array
    {
        $data = $json !== null ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $pairs = [];
        foreach (self::DETAIL_KEYS as $key => $label) {
            if (isset($data[$key]) && $data[$key] !== [] && $data[$key] !== '') {
                $value = $key === 'changes' && is_array($data[$key])
                    ? array_map(static fn ($f) => self::FIELDS[$f] ?? (string) $f, $data[$key])
                    : $data[$key];
                $pairs[] = [$label, is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value];
            }
        }
        return $pairs;
    }
}
