<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Maintenance;
use RuntimeException;

final class InfrastructureTest extends IntegrationTestCase
{
    public function testMailQueueRetriesThenFails(): void
    {
        $queue = $this->app->mailQueue();
        $queue->push('a@kibarou.test', 'Sujet', 'Corps');
        $fail = static function (): void {
            throw new RuntimeException('SMTP indisponible');
        };
        $this->assertSame(['sent' => 0, 'failed' => 1], $queue->process($fail, 10, 2));
        $row = $this->app->db()->fetch('SELECT * FROM mail_queue');
        $this->assertSame('pending', $row['status']);
        $this->assertSame('SMTP indisponible', $row['last_error']);
        // Pas de nouvel essai avant le délai
        $this->assertSame(['sent' => 0, 'failed' => 0], $queue->process($fail, 10, 2));
        $this->app->db()->run('UPDATE mail_queue SET available_at = UTC_TIMESTAMP()');
        $queue->process($fail, 10, 2);
        $this->assertSame('failed', $this->app->db()->value('SELECT status FROM mail_queue'));
    }

    public function testMailQueueSends(): void
    {
        $this->app->mailQueue()->push('a@kibarou.test', 'Sujet', 'Corps');
        $sent = [];
        $stats = $this->app->mailQueue()->process(function (string $to, string $s, string $b) use (&$sent) {
            $sent[] = $to;
        }, 10, 3);
        $this->assertSame(['a@kibarou.test'], $sent);
        $this->assertSame(1, $stats['sent']);
        $this->assertSame('sent', $this->app->db()->value('SELECT status FROM mail_queue'));
    }

    public function testMaintenancePurgesExpiredData(): void
    {
        $db = $this->app->db();
        $db->insert('login_attempts', ['email' => 'x', 'ip_address' => '1.1.1.1', 'success' => 0, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-200 days'))]);
        $db->insert('login_attempts', ['email' => 'y', 'ip_address' => '1.1.1.1', 'success' => 0, 'created_at' => now_utc()]);
        $db->insert('sessions', ['id' => str_repeat('f', 64), 'ip_address' => '1.1.1.1', 'data' => '{}', 'created_at' => now_utc(), 'last_activity_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day'))]);
        $result = (new Maintenance($db, $this->app->config('retention'), $this->app->config('session')))->run();
        $this->assertSame(1, $result['login_attempts']);
        $this->assertGreaterThanOrEqual(1, $result['sessions']);
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM login_attempts'));
    }

    public function testUnknownRouteGives404WithoutTechnicalDetails(): void
    {
        $r = $this->get('/n-existe-pas');
        $this->assertSame(404, $r->status);
        $this->assertStringNotContainsString('Exception', $r->body);
    }

    public function testInstallerRequiresTokenAndDisappearsAfterInstall(): void
    {
        $db = $this->app->db();
        // Simule une base sans super administrateur
        $db->run("DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super_admin'");
        $token = str_repeat('a', 40);
        $this->assertSame(404, $this->get('/install')->status);
        $this->assertSame(404, $this->get('/install', ['token' => 'mauvais'])->status);
        $this->assertSame(200, $this->get('/install', ['token' => $token])->status);
        $r = $this->post('/install', ['token' => $token, 'first_name' => 'Pape', 'last_name' => 'Admin', 'email' => 'pape@kibarou.test',
            'password' => 'faible', 'password_confirmation' => 'faible'], false);
        $this->assertSame(422, $r->status);
        $r = $this->post('/install', ['token' => $token, 'first_name' => 'Pape', 'last_name' => 'Admin', 'email' => 'pape@kibarou.test',
            'password' => 'Installation2026', 'password_confirmation' => 'Installation2026'], false);
        $this->assertSame(200, $r->status);
        $this->assertSame(404, $this->get('/install', ['token' => $token])->status, 'Installateur désactivé après création');
        $this->assertRedirect($this->login('pape@kibarou.test', 'Installation2026'), '/');
        $this->assertSame(200, $this->get('/admin/roles')->status);
    }
}
