<?php

declare(strict_types=1);

namespace Tests\Integration;

final class NotificationCenterTest extends IntegrationTestCase
{
    private function notify(int $u, string $title, ?string $url = null): int
    {
        return (int) $this->app->notifier()->notify($u, 'presence.changed', $title, $url);
    }

    public function testBellShowsUnreadCount(): void
    {
        $me = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertStringNotContainsString('data-unread-badge>', $this->get('/')->body);
        $this->notify($me, 'Un');
        $this->notify($me, 'Deux');
        $body = $this->get('/')->body;
        $this->assertStringContainsString('Notifications, 2 non lues', $body);
        $this->assertMatchesRegularExpression('/data-unread-badge[^>]*>2</', $body);
    }

    public function testListShowsOwnNotificationsNewestFirst(): void
    {
        $me = $this->createUser('moi@kibarou.test');
        $other = $this->createUser('autre@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('Aucune notification pour l\'instant.', $this->get('/notifications')->body);
        $this->notify($me, 'Premier titre');
        $this->notify($other, 'Titre d\'un autre');
        $this->notify($me, 'Second titre');
        $body = $this->get('/notifications')->body;
        $this->assertStringNotContainsString('Titre d&apos;un autre', $body);
        $this->assertLessThan(strpos($body, 'Premier titre'), strpos($body, 'Second titre'));
    }

    public function testOpenMarksReadAndRedirects(): void
    {
        $me = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $id = $this->notify($me, 'Avec lien', '/profile');
        $this->assertRedirect($this->post("/notifications/$id/open"), '/profile');
        $this->assertNotNull($this->app->db()->value('SELECT read_at FROM notifications WHERE id = ?', [$id]));
    }

    public function testCannotOpenSomeoneElsesNotification(): void
    {
        $this->createUser('moi@kibarou.test');
        $other = $this->createUser('autre@kibarou.test');
        $id = $this->notify($other, 'Privé');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertSame(404, $this->post("/notifications/$id/open")->status);
        $this->assertNull($this->app->db()->value('SELECT read_at FROM notifications WHERE id = ?', [$id]));
    }

    public function testReadAll(): void
    {
        $me = $this->createUser('moi@kibarou.test');
        $other = $this->createUser('autre@kibarou.test');
        $this->notify($me, 'a');
        $this->notify($me, 'b');
        $theirs = $this->notify($other, 'c');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->post('/notifications/read-all'), '/notifications');
        $this->assertSame(0, $this->app->notifier()->unreadCount($me));
        $this->assertNull($this->app->db()->value('SELECT read_at FROM notifications WHERE id = ?', [$theirs]));
    }
}
