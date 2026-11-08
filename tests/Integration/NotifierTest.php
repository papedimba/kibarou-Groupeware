<?php

declare(strict_types=1);

namespace Tests\Integration;

final class NotifierTest extends IntegrationTestCase
{
    private function row(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM notifications WHERE id = ?', [$id]);
    }

    private function mails(): int
    {
        return (int) $this->app->db()->value('SELECT COUNT(*) FROM mail_queue');
    }

    public function testImmediateTypeQueuesEmail(): void
    {
        $u = $this->createUser('moi@kibarou.test');
        $id = $this->app->notifier()->notify($u, 'account.enabled', 'Votre compte a été réactivé', '/');
        $this->assertSame('queued', $this->row($id)['email_status']);
        $this->assertSame(1, $this->mails());
        $this->assertStringContainsString('https://gw.test/', (string) $this->app->db()->value('SELECT body_text FROM mail_queue'));
        $this->assertStringContainsString('/profile/notifications', (string) $this->app->db()->value('SELECT body_text FROM mail_queue'));
    }

    public function testDigestTypeDoesNotQueueEmail(): void
    {
        $u = $this->createUser('moi@kibarou.test');
        $id = $this->app->notifier()->notify($u, 'presence.changed', 'Statut modifié');
        $this->assertSame('digest_pending', $this->row($id)['email_status']);
        $this->assertSame(0, $this->mails());
    }

    public function testPreferenceOffAndMandatoryOverride(): void
    {
        $u = $this->createUser('moi@kibarou.test');
        $db = $this->app->db();
        $db->insert('notification_preferences', ['user_id' => $u, 'type' => 'account.enabled', 'email' => 'off', 'browser' => 0]);
        $db->insert('notification_preferences', ['user_id' => $u, 'type' => 'security.password_changed', 'email' => 'off', 'browser' => 0]);
        $a = $this->app->notifier()->notify($u, 'account.enabled', 'Réactivé');
        $this->assertSame('none', $this->row($a)['email_status']);
        $this->assertSame(0, $this->mails());
        $b = $this->app->notifier()->notify($u, 'security.password_changed', 'Mot de passe modifié');
        $this->assertSame('queued', $this->row($b)['email_status']);
        $this->assertSame(1, $this->mails());
    }

    public function testUnknownTypeThrows(): void
    {
        $u = $this->createUser('moi@kibarou.test');
        $this->expectException(\InvalidArgumentException::class);
        $this->app->notifier()->notify($u, 'inconnu.type', 'x');
    }

    public function testInactiveRecipientIsIgnored(): void
    {
        $u = $this->createUser('parti@kibarou.test', ['collaborateur'], 'Motdepasse123', ['status' => 'disabled']);
        $this->assertNull($this->app->notifier()->notify($u, 'account.enabled', 'x'));
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications'));
    }

    public function testExternalUrlIsDropped(): void
    {
        $u = $this->createUser('moi@kibarou.test');
        $this->assertNull($this->row($this->app->notifier()->notify($u, 'presence.changed', 'x', 'https://evil.test'))['url']);
        $this->assertNull($this->row($this->app->notifier()->notify($u, 'presence.changed', 'x', '//evil.test'))['url']);
        $this->assertNull($this->row($this->app->notifier()->notify($u, 'presence.changed', 'x', '/\\evil.test'))['url']);
        $this->assertSame('/profile', $this->row($this->app->notifier()->notify($u, 'presence.changed', 'x', '/profile'))['url']);
    }

    public function testUnreadCount(): void
    {
        $u = $this->createUser('moi@kibarou.test');
        $id = $this->app->notifier()->notify($u, 'presence.changed', 'a');
        $this->app->notifier()->notify($u, 'presence.changed', 'b');
        $this->app->db()->run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        $this->assertSame(1, $this->app->notifier()->unreadCount($u));
    }
}
