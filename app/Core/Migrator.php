<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Migrations SQL versionnées : database/migrations/NNNN_nom.sql, appliquées dans l'ordre
 * et enregistrées dans la table `migrations`. Pas de retour arrière automatique :
 * on corrige par une nouvelle migration (sauvegarde obligatoire avant mise à jour).
 */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    /** @return list<string> migrations appliquées pendant cet appel */
    public function migrate(): array
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                name VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $done = array_column($this->db->fetchAll('SELECT name FROM migrations'), 'name');
        $applied = [];
        foreach ($this->pending($done) as $name => $file) {
            // MySQL valide implicitement chaque DDL : une migration doit rester petite et sûre.
            foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->insert('migrations', ['name' => $name, 'applied_at' => now_utc()]);
            $applied[] = $name;
        }
        return $applied;
    }

    /**
     * @param list<string> $done
     * @return array<string,string>
     */
    private function pending(array $done): array
    {
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.sql');
            if (!in_array($name, $done, true)) {
                $pending[$name] = $file;
            }
        }
        return $pending;
    }

    /**
     * Découpe un script en instructions (séparateur « ; » en fin de ligne, hors commentaires « -- »).
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn ($l) => !str_starts_with(ltrim($l), '--'),
        );
        $parts = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
