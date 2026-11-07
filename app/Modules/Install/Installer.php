<?php

declare(strict_types=1);

namespace App\Modules\Install;

use App\Core\Database;
use App\Core\Migrator;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Modules\Auth\PasswordPolicy;
use App\Services\AuditLogger;
use PDOException;

/**
 * Création du premier super administrateur, partagée par la commande CLI et l'assistant web
 * (le second sert quand l'hébergement n'offre pas de SSH). Ne fonctionne que tant qu'aucun
 * super administrateur n'existe.
 */
final class Installer
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsDir,
        private readonly int $passwordMinLength,
    ) {
    }

    public function needsInstall(): bool
    {
        try {
            return (int) $this->db->value(
                "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super_admin'",
            ) === 0;
        } catch (PDOException) {
            return true; // tables absentes : base pas encore migrée
        }
    }

    /** @return list<string> */
    public function migrate(): array
    {
        return (new Migrator($this->db, $this->migrationsDir))->migrate();
    }

    /** @param array<string,mixed> $input */
    public function createSuperAdmin(array $input): int
    {
        if (!$this->needsInstall()) {
            throw new ValidationException(['email' => 'Un super administrateur existe déjà.']);
        }
        $data = Validator::validate($input, [
            'email' => 'required|email|max:190',
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'password' => 'required',
            'password_confirmation' => 'required|same:password',
        ], ['email' => 'Email', 'first_name' => 'Prénom', 'last_name' => 'Nom', 'password' => 'Mot de passe', 'password_confirmation' => 'Confirmation']);
        $email = mb_strtolower((string) $data['email']);
        $policy = PasswordPolicy::check((string) $input['password'], $this->passwordMinLength, $email);
        if ($policy !== null) {
            throw new ValidationException(['password' => $policy]);
        }
        return $this->db->transaction(function () use ($data, $email, $input) {
            $id = $this->db->insert('users', [
                'email' => $email,
                'password_hash' => PasswordPolicy::hash((string) $input['password']),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'status' => 'active',
                'password_changed_at' => now_utc(),
                'created_at' => now_utc(),
                'updated_at' => now_utc(),
            ]);
            $this->db->run("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'super_admin'", [$id]);
            (new AuditLogger($this->db))->log($id, 'install.super_admin_created', 'user', $id);
            return $id;
        });
    }
}
