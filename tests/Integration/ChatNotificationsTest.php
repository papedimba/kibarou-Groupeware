<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Chat\ChatRepository;

final class ChatNotificationsTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function repo(): ChatRepository
    {
        return new ChatRepository($this->app->db());
    }

    /** @return list<array<string,mixed>> */
    private function chatNotifications(int $userId): array
    {
        return $this->app->db()->fetchAll("SELECT title, body, url, read_at FROM notifications WHERE user_id = ? AND type = 'chat.message' ORDER BY id", [$userId]);
    }

    public function testUnreadCountsAndMarkRead(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($a), $cid, 'de moi');
        $last = 0;
        for ($i = 0; $i < 3; $i++) {
            $last = $this->app->chat()->send($this->u($b), $cid, "m$i")['event_id'];
        }
        $this->assertSame(3, $this->repo()->conversationsFor($a)[0]['unread']);
        $this->assertSame(3, $this->repo()->unreadTotal($a));
        $this->assertSame(1, $this->repo()->unreadTotal($b), 'mes propres messages ne comptent pas, ceux de l\'autre oui');
        $this->app->chat()->markRead($this->u($a), $cid, $last);
        $this->assertSame(0, $this->repo()->unreadTotal($a));
        $this->app->chat()->markRead($this->u($a), $cid, 1);
        $this->assertSame(0, $this->repo()->unreadTotal($a), 'jamais à la baisse');
    }

    public function testOneNotificationPerConversationUntilRead(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Moussa', 'last_name' => 'Traoré']);
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $last = 0;
        for ($i = 0; $i < 3; $i++) {
            $last = $this->app->chat()->send($this->u($b), $cid, "m$i")['event_id'];
        }
        $n = $this->chatNotifications($a);
        $this->assertCount(1, $n);
        $this->assertSame(['Nouveau message de Moussa Traoré', "/chat/$cid"], [$n[0]['title'], $n[0]['url']]);
        $this->app->chat()->markRead($this->u($a), $cid, $last);
        $this->assertNotNull($this->chatNotifications($a)[0]['read_at'], 'lire la conversation lit la notification');
        $this->app->chat()->send($this->u($b), $cid, 'juste après lecture');
        $this->assertCount(1, $this->chatNotifications($a), 'lu il y a moins de 2 min : pas de notification');
        $this->app->db()->run('UPDATE conversation_members SET last_read_at = UTC_TIMESTAMP() - INTERVAL 3 MINUTE WHERE user_id = ?', [$a]);
        $this->app->chat()->send($this->u($b), $cid, 'plus tard');
        $this->assertCount(2, $this->chatNotifications($a));
    }

    public function testGroupNotificationTitleHasGroupName(): void
    {
        $a = $this->createUser('a@kibarou.test', ['collaborateur'], 'Motdepasse123', ['first_name' => 'Awa', 'last_name' => 'Diallo']);
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $cid = $this->app->chat()->createGroup($this->u($a), 'Chantier Pont', [$b, $c]);
        $this->app->chat()->send($this->u($a), $cid, 'Réunion à 8 h');
        $this->assertSame('Nouveau message de Awa Diallo dans Chantier Pont', $this->chatNotifications($b)[0]['title']);
        $this->assertSame([], $this->chatNotifications($a));
    }

    public function testNoMessageContentInNotificationsOrMail(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->app->db()->insert('notification_preferences', ['user_id' => $a, 'type' => 'chat.message', 'email' => 'immediate', 'browser' => 1]);
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($b), $cid, 'secret-456');
        $this->assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM mail_queue'));
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE title LIKE '%secret-456%' OR body LIKE '%secret-456%'"));
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM mail_queue WHERE body_text LIKE '%secret-456%' OR subject LIKE '%secret-456%'"));
    }
}
