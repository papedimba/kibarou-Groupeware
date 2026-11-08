<?php

declare(strict_types=1);

namespace Tests\Integration;

final class NotificationTriggersTest extends IntegrationTestCase
{
    /** @return list<array<string,mixed>> */
    private function notifications(int $userId): array
    {
        return $this->app->db()->fetchAll('SELECT type, title, body, email_status FROM notifications WHERE user_id = ? ORDER BY id', [$userId]);
    }

    public function testPasswordChangeNotifiesAndEmails(): void
    {
        $me = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->post('/profile/password', ['current_password' => 'Motdepasse123', 'password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x']);
        [$n] = $this->notifications($me);
        $this->assertSame(['security.password_changed', 'Votre mot de passe a été modifié', 'queued'], [$n['type'], $n['title'], $n['email_status']]);
        $this->assertStringContainsString('contactez immédiatement un administrateur', (string) $this->app->db()->value("SELECT body_text FROM mail_queue WHERE to_email = 'moi@kibarou.test'"));
    }

    public function testForcedFirstChangeDoesNotNotify(): void
    {
        $me = $this->createUser('neuf@kibarou.test', ['collaborateur'], 'Temporaire123', ['must_change_password' => 1]);
        $this->login('neuf@kibarou.test', 'Temporaire123');
        $this->post('/profile/password', ['current_password' => 'Temporaire123', 'password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x']);
        $this->assertSame(0, (int) $this->app->db()->value('SELECT must_change_password FROM users WHERE id = ?', [$me]));
        $this->assertSame([], $this->notifications($me));
    }

    public function testResetNotifies(): void
    {
        $me = $this->createUser('moi@kibarou.test');
        $token = (string) $this->app->passwordResets()->request('moi@kibarou.test', 'https://gw.test');
        $this->get("/password/reset/$token");
        $this->post("/password/reset/$token", ['password' => 'Nouveau2026x', 'password_confirmation' => 'Nouveau2026x']);
        $this->assertSame(['security.password_changed'], array_column($this->notifications($me), 'type'));
    }

    public function testAccountEnabledNotifies(): void
    {
        $target = $this->createUser('cible@kibarou.test', ['collaborateur'], 'Motdepasse123', ['status' => 'disabled']);
        $this->createUser('rh@kibarou.test', ['admin']);
        $this->login('rh@kibarou.test', 'Motdepasse123');
        $this->post("/admin/users/$target/status", ['status' => 'active']);
        $this->assertSame(['account.enabled'], array_column($this->notifications($target), 'type'));
        $this->post("/admin/users/$target/status", ['status' => 'disabled']);
        $this->assertCount(1, $this->notifications($target), 'pas de notification à la désactivation');
    }

    public function testPresenceChangedByOtherNotifies(): void
    {
        $head = $this->createUser('chef@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Moussa', 'last_name' => 'Traoré']);
        $dept = $this->app->db()->insert('departments', ['name' => 'Travaux', 'manager_user_id' => $head, 'is_active' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $a = $this->createUser('a@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $dept]);
        $b = $this->createUser('b@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $dept]);

        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->post('/presence', ['status' => 'office']);
        $this->assertSame([], $this->notifications($a), 'pas de notification pour soi-même');

        $this->cookies = [];
        $this->login('chef@kibarou.test', 'Motdepasse123');
        $this->post('/presence', ['status' => 'site', 'site_label' => 'Pont', 'user_id' => (string) $a]);
        $this->assertSame('Votre statut a été modifié par Moussa Traoré : Sur chantier', $this->notifications($a)[0]['title']);
        $this->post('/presence/clear', ['user_id' => (string) $a]);
        $this->assertSame('Votre statut a été effacé par Moussa Traoré', $this->notifications($a)[1]['title']);
        $this->post('/presence/team', ['status' => 'absent', 'user_ids' => [(string) $a, (string) $b]]);
        $this->assertCount(3, $this->notifications($a));
        $this->assertCount(1, $this->notifications($b));
    }
}
