<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Admin\AuditLabels;
use PHPUnit\Framework\TestCase;

final class AuditLabelsTest extends TestCase
{
    public function testEveryLoggedActionHasALabel(): void
    {
        $actions = ['auth.reset_requested', 'auth.invitation_sent', 'user.enabled', 'user.disabled',
            'department.created', 'department.updated', 'position.created', 'position.updated'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app'));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php'
                && preg_match_all("/->log\\([^,]+,\\s*'([a-z_]+\\.[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m)) {
                array_push($actions, ...$m[1]);
            }
        }
        $this->assertGreaterThan(15, count(array_unique($actions)));
        foreach (array_unique($actions) as $action) {
            $this->assertNotSame($action, AuditLabels::label($action), "Libellé manquant pour $action");
        }
    }

    public function testUnknownActionFallsBack(): void
    {
        $this->assertSame('futur.x', AuditLabels::label('futur.x'));
        $this->assertSame([], AuditLabels::details(null));
        $this->assertSame([['Email', 'a@b.c']], AuditLabels::details('{"email":"a@b.c","roles":[5]}'));
        $this->assertSame([['Champs modifiés', 'phone, last_name']], AuditLabels::details('{"changes":["phone","last_name"]}'));
    }

    public function testAlerts(): void
    {
        $this->assertTrue(AuditLabels::isAlert('auth.login_failed'));
        $this->assertFalse(AuditLabels::isAlert('auth.login'));
    }
}
