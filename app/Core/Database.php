<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/** Fine couche au-dessus de PDO : requêtes préparées uniquement, session SQL en UTC. */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array{host:string,port:int,name:string,user:string,pass:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $this->pdo = new PDO(
                "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
                $c['user'],
                $c['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
            $this->pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        }
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(
                is_int($key) ? $key + 1 : $key,
                $value,
                match (true) {
                    is_int($value), is_bool($value) => PDO::PARAM_INT,
                    $value === null => PDO::PARAM_NULL,
                    default => PDO::PARAM_STR,
                },
            );
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * Insère une ligne. Les noms de colonnes viennent du code, jamais de l'utilisateur.
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $cols),
            implode(',', array_map(static fn ($c) => ':' . $c, $cols)),
        );
        $this->run($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(',', array_map(static fn ($c) => "`$c` = :s_$c", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn ($c) => "`$c` = :w_$c", array_keys($where)));
        $params = [];
        foreach ($data as $k => $v) {
            $params["s_$k"] = $v;
        }
        foreach ($where as $k => $v) {
            $params["w_$k"] = $v;
        }
        return $this->run("UPDATE `$table` SET $set WHERE $cond", $params)->rowCount();
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
