<?php

declare(strict_types=1);

namespace Tests\Integration;

final class NotificationApiTest extends IntegrationTestCase
{
    private function sessionId(): string
    {
        return hash('sha256', $this->cookies['kb_session']);
    }

    public function testReturnsUnreadAfterSince(): void
    {
        $me = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $n = $this->app->notifier();
        $first = (int) $n->notify($me, 'presence.changed', 'Un');
        $n->notify($me, 'presence.changed', 'Deux', '/profile');
        $n->notify($me, 'account.enabled', 'Trois');
        $r = $this->get('/api/notifications', ['since' => (string) $first]);
        $this->assertSame(200, $r->status);
        $this->assertStringStartsWith('application/json', $r->headers['Content-Type']);
        $data = json_decode($r->body, true);
        $this->assertSame(3, $data['unread']);
        $this->assertSame(['Deux', 'Trois'], array_column($data['items'], 'title'));
        $this->assertSame([true, false], array_column($data['items'], 'alert'));
        $this->assertSame('/profile', $data['items'][0]['url']);
    }

    public function testPollingDoesNotExtendSession(): void
    {
        $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $past = gmdate('Y-m-d H:i:s', time() - 600);
        $this->app->db()->run('UPDATE sessions SET last_activity_at = ? WHERE id = ?', [$past, $this->sessionId()]);
        $this->assertSame(200, $this->get('/api/notifications')->status);
        $this->assertSame($past, $this->app->db()->value('SELECT last_activity_at FROM sessions WHERE id = ?', [$this->sessionId()]));
    }

    public function testPollingDoesNotConsumeFlash(): void
    {
        $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->post('/profile', ['phone' => '+223 20 00 00 09']), '/profile');
        $this->get('/api/notifications');
        $this->assertStringContainsString('Profil mis à jour.', $this->get('/profile')->body);
    }

    public function testExpiredSessionReturns401Json(): void
    {
        $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->app->db()->run('UPDATE sessions SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR WHERE id = ?', [$this->sessionId()]);
        $r = $this->get('/api/notifications');
        $this->assertSame(401, $r->status);
        $this->assertStringStartsWith('application/json', $r->headers['Content-Type']);
    }

    public function testMustChangePasswordGets403Json(): void
    {
        $this->createUser('neuf@kibarou.test', ['collaborateur'], 'Temporaire123', ['must_change_password' => 1]);
        $this->login('neuf@kibarou.test', 'Temporaire123');
        $r = $this->get('/api/notifications');
        $this->assertSame(403, $r->status);
        $this->assertStringStartsWith('application/json', $r->headers['Content-Type']);
    }

    public function testCspAllowsOwnServiceWorker(): void
    {
        $this->assertStringContainsString("worker-src 'self'", $this->get('/login')->headers['Content-Security-Policy']);
    }

    public function testSubdirectoryInstallPrefixesUrls(): void
    {
        // Application installée dans un sous-dossier, sur sa propre connexion (et sa propre transaction de test).
        $this->app->db()->pdo()->rollBack();
        $this->app = test_app(['base_path' => '/intranet']);
        $this->app->db()->pdo()->beginTransaction();
        $me = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->app->notifier()->notify($me, 'presence.changed', 'Lien', '/profile');
        $data = json_decode($this->get('/api/notifications')->body, true);
        $this->assertSame('/intranet/profile', $data['items'][0]['url']);
        $this->assertStringContainsString('data-base="/intranet/"', $this->get('/')->body);
    }

    public function testErrorPageKeepsBellState(): void
    {
        $me = $this->createUser('moi@kibarou.test');
        $id = (int) $this->app->notifier()->notify($me, 'presence.changed', 'Ancienne');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $r = $this->get('/admin');
        $this->assertSame(403, $r->status);
        $this->assertStringContainsString('data-last-id="' . $id . '"', $r->body);
        $this->assertStringContainsString('Notifications, 1 non lue', $r->body);
    }
}
