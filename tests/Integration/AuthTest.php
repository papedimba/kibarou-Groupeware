<?php

declare(strict_types=1);

namespace Tests\Integration;

final class AuthTest extends IntegrationTestCase
{
    public function testGuestIsRedirectedToLogin(): void
    {
        $this->assertRedirect($this->get('/'), '/login');
    }

    public function testLoginPageHasSecurityHeadersAndCsrf(): void
    {
        $r = $this->get('/login');
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertArrayHasKey('Strict-Transport-Security', $r->headers);
        $this->assertMatchesRegularExpression('/name="_csrf" value="[a-f0-9]{64}"/', $r->body);
        $cookie = $r->cookies[0]['options'];
        $this->assertTrue($cookie['httponly']);
        $this->assertTrue($cookie['secure']);
        $this->assertSame('Lax', $cookie['samesite']);
    }

    public function testSuccessfulLoginRegeneratesSessionAndAudits(): void
    {
        $id = $this->createUser('jean@kibarou.test');
        $this->get('/login');
        $before = $this->cookies['kb_session'];
        $r = $this->post('/login', ['email' => 'JEAN@kibarou.test', 'password' => 'Motdepasse123']);
        $this->assertRedirect($r, '/');
        $this->assertNotSame($before, $this->cookies['kb_session'], 'La session doit être régénérée');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM sessions WHERE id = ?', [hash('sha256', $before)]));
        $this->assertSame(200, $this->get('/')->status);
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login' AND user_id = ?", [$id]));
        $this->assertNotNull($this->app->db()->value('SELECT last_login_at FROM users WHERE id = ?', [$id]));
    }

    public function testSessionTokenIsStoredHashedOnly(): void
    {
        $this->createUser('hash@kibarou.test');
        $this->login('hash@kibarou.test', 'Motdepasse123');
        $token = $this->cookies['kb_session'];
        $this->assertNull($this->app->db()->value('SELECT id FROM sessions WHERE id = ?', [$token]));
        $this->assertNotNull($this->app->db()->value('SELECT id FROM sessions WHERE id = ?', [hash('sha256', $token)]));
    }

    public function testWrongPasswordGivesGenericError(): void
    {
        $this->createUser('jean@kibarou.test');
        $this->assertRedirect($this->login('jean@kibarou.test', 'mauvais'), '/login');
        $body = $this->get('/login')->body;
        $this->assertStringContainsString('Email ou mot de passe incorrect.', $body);
        $this->assertRedirect($this->login('inconnu@kibarou.test', 'mauvais'), '/login');
        $this->assertStringContainsString('Email ou mot de passe incorrect.', $this->get('/login')->body);
    }

    public function testThrottlingAfterFiveFailures(): void
    {
        $this->createUser('cible@kibarou.test');
        for ($i = 0; $i < 5; $i++) {
            $this->login('cible@kibarou.test', 'mauvais' . $i);
        }
        $r = $this->login('cible@kibarou.test', 'Motdepasse123');
        $this->assertSame(429, $r->status, 'Même le bon mot de passe est refusé pendant le blocage');
        // Une autre IP reste bloquée pour cet email
        $this->cookies = [];
        $this->assertSame(429, $this->login('cible@kibarou.test', 'Motdepasse123', '10.9.9.9')->status);
    }

    public function testThrottlingPerIp(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->login("x$i@kibarou.test", 'mauvais', '10.6.6.6');
        }
        $this->createUser('autre@kibarou.test');
        $this->assertSame(429, $this->login('autre@kibarou.test', 'Motdepasse123', '10.6.6.6')->status);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $this->createUser('jean@kibarou.test');
        $this->get('/login');
        $r = $this->post('/login', ['email' => 'jean@kibarou.test', 'password' => 'Motdepasse123'], false);
        $this->assertSame(419, $r->status);
        $r = $this->post('/login', ['email' => 'jean@kibarou.test', 'password' => 'Motdepasse123', '_csrf' => str_repeat('0', 64)]);
        $this->assertSame(419, $r->status);
    }

    public function testDisabledUserCannotLoginAndIsLoggedOut(): void
    {
        $id = $this->createUser('jean@kibarou.test');
        $this->login('jean@kibarou.test', 'Motdepasse123');
        $this->app->db()->update('users', ['status' => 'disabled'], ['id' => $id]);
        $this->assertRedirect($this->get('/'), '/login');
        $this->login('jean@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('désactivé', $this->get('/login')->body);
    }

    public function testLogoutDestroysSession(): void
    {
        $this->createUser('jean@kibarou.test');
        $this->login('jean@kibarou.test', 'Motdepasse123');
        $token = $this->cookies['kb_session'];
        $this->assertRedirect($this->post('/logout'), '/login');
        $this->assertNull($this->app->db()->value('SELECT id FROM sessions WHERE id = ?', [hash('sha256', $token)]));
        // Rejouer l'ancien cookie ne redonne pas accès
        $this->cookies = ['kb_session' => $token];
        $this->assertRedirect($this->get('/'), '/login');
    }

    public function testIdleSessionExpires(): void
    {
        $this->createUser('jean@kibarou.test');
        $this->login('jean@kibarou.test', 'Motdepasse123');
        $this->app->db()->run('UPDATE sessions SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR');
        $this->assertRedirect($this->get('/'), '/login');
    }

    public function testMustChangePasswordForcesRedirect(): void
    {
        $this->createUser('neuf@kibarou.test', ['collaborateur'], 'Temporaire123', ['must_change_password' => 1]);
        $this->login('neuf@kibarou.test', 'Temporaire123');
        $this->assertRedirect($this->get('/'), '/profile/password');
        $this->assertSame(200, $this->get('/profile/password')->status);
        $r = $this->post('/profile/password', ['current_password' => 'Temporaire123', 'password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x']);
        $this->assertRedirect($r, '/profile');
        $this->assertSame(200, $this->get('/')->status);
    }

    public function testIntendedRedirectOnlyInternal(): void
    {
        $this->createUser('jean@kibarou.test', ['admin']);
        $this->get('/admin/users');
        $this->assertRedirect($this->login('jean@kibarou.test', 'Motdepasse123'), '/admin/users');
    }

    public function testPasswordChangeRevokesOtherSessions(): void
    {
        $id = $this->createUser('jean@kibarou.test');
        $this->login('jean@kibarou.test', 'Motdepasse123', '10.0.0.2');
        $first = $this->cookies;
        $this->cookies = [];
        $this->login('jean@kibarou.test', 'Motdepasse123');
        $this->assertSame(2, (int) $this->app->db()->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$id]));
        $this->post('/profile/password', ['current_password' => 'Motdepasse123', 'password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x']);
        $this->assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$id]));
        $this->cookies = $first;
        $this->assertRedirect($this->get('/'), '/login');
    }
}
