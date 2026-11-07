<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testSplitsStatementsAndSkipsComments(): void
    {
        $sql = "-- commentaire ; ignoré\nCREATE TABLE a (x INT);\n\nINSERT INTO a VALUES ('l''a;b');\n-- fin";
        $this->assertSame(["CREATE TABLE a (x INT)", "INSERT INTO a VALUES ('l''a;b')"], Migrator::splitStatements($sql));
    }

    public function testRealMigrationFilesParse(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') as $file) {
            $this->assertNotEmpty(Migrator::splitStatements((string) file_get_contents($file)), basename($file));
        }
    }
}
