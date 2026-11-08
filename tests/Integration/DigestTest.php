<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Notifications\DigestService;

final class DigestTest extends IntegrationTestCase
{
    private function digest(string $localDateTime): int
    {
        $tz = new \DateTimeZone((string) $this->app->config('app.timezone'));
        return (new DigestService($this->app->db(), $this->app->mailQueue(), 'https://gw.test'))->run(new \DateTimeImmutable($localDateTime, $tz));
    }

    private function pending(int $u, string $title): int
    {
        return (int) $this->app->notifier()->notify($u, 'presence.changed', $title);
    }

    private function mailsTo(string $email): array
    {
        return array_column($this->app->db()->fetchAll('SELECT body_text FROM mail_queue WHERE to_email = ?', [$email]), 'body_text');
    }

    public function testNothingBefore7am(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $id = $this->pending($a, 'Titre');
        $this->assertSame(0, $this->digest('2026-11-09 06:59:00'));
        $this->assertSame('digest_pending', $this->app->db()->value('SELECT email_status FROM notifications WHERE id = ?', [$id]));
    }

    public function testDigestGroupsUnreadPerUser(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->pending($a, 'Premier non lu');
        $this->pending($a, 'Second non lu');
        $read = $this->pending($a, 'Déjà lu');
        $this->app->db()->run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE id = ?', [$read]);
        $this->pending($b, 'Pour B');
        $this->assertSame(2, $this->digest('2026-11-09 07:05:00'));
        [$mail] = $this->mailsTo('a@kibarou.test');
        $this->assertStringContainsString('Premier non lu', $mail);
        $this->assertStringContainsString('Second non lu', $mail);
        $this->assertStringNotContainsString('Déjà lu', $mail);
        $this->assertStringContainsString('https://gw.test/notifications', $mail);
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE email_status = 'digest_pending'"));
        $this->assertSame('Vos notifications du 09/11/2026', $this->app->db()->value("SELECT subject FROM mail_queue WHERE to_email = 'a@kibarou.test'"));
    }

    public function testDigestRunsOncePerLocalDay(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $this->pending($a, 'Lundi');
        $this->assertSame(1, $this->digest('2026-11-09 07:05:00'));
        $this->pending($a, 'Lundi après 7 h');
        $this->assertSame(0, $this->digest('2026-11-09 09:00:00'));
        $this->assertSame(1, $this->digest('2026-11-10 07:01:00'));
        $this->assertCount(2, $this->mailsTo('a@kibarou.test'));
    }

    public function testDigestCapsAt20Titles(): void
    {
        $a = $this->createUser('a@kibarou.test');
        for ($i = 1; $i <= 25; $i++) {
            $this->pending($a, "Notification $i");
        }
        $this->digest('2026-11-09 07:05:00');
        $this->assertStringContainsString('et 5 autres', $this->mailsTo('a@kibarou.test')[0]);
    }
}
