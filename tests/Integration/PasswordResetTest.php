<?php

declare(strict_types=1);

namespace Tests\Integration;

final class PasswordResetTest extends IntegrationTestCase
{
    public function testSameAnswerForUnknownEmail(): void
    {
        $this->get('/password/forgot');
        $this->assertRedirect($this->post('/password/forgot', ['email' => 'inconnu@kibarou.test']), '/login');
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM mail_queue'));
    }

    public function testFullResetFlow(): void
    {
        $id = $this->createUser('jean@kibarou.test');
        $token = $this->app->passwordResets()->request('jean@kibarou.test', 'https://gw.test');
        $this->assertNotNull($token);
        $mail = (string) $this->app->db()->value("SELECT body_text FROM mail_queue WHERE to_email = 'jean@kibarou.test'");
        $this->assertStringContainsString("https://gw.test/password/reset/$token", $mail);
        $this->assertNotSame($token, $this->app->db()->value('SELECT token_hash FROM password_resets WHERE user_id = ?', [$id]));

        $this->assertSame(200, $this->get("/password/reset/$token")->status);
        $this->assertRedirect($this->post("/password/reset/$token", ['password' => 'court', 'password_confirmation' => 'court']), "/password/reset/$token");
        $this->assertRedirect($this->post("/password/reset/$token", ['password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x']), '/login');
        // Usage unique
        $this->assertRedirect($this->get("/password/reset/$token"), '/password/forgot');
        $this->assertRedirect($this->login('jean@kibarou.test', 'Nouveau2026x'), '/');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->createUser('jean@kibarou.test');
        $token = (string) $this->app->passwordResets()->request('jean@kibarou.test', 'https://gw.test');
        $this->app->db()->run('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        $this->assertRedirect($this->get("/password/reset/$token"), '/password/forgot');
    }

    public function testRequestsAreRateLimited(): void
    {
        $this->createUser('jean@kibarou.test');
        $resets = $this->app->passwordResets();
        for ($i = 0; $i < 3; $i++) {
            $this->assertNotNull($resets->request('jean@kibarou.test', 'https://gw.test'));
        }
        $this->assertNull($resets->request('jean@kibarou.test', 'https://gw.test'));
        $this->assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL'), 'Un seul lien valide à la fois');
    }
}
