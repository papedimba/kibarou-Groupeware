<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;

final class ChatActionsTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed> */
    private function json(Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    private function age(int $messageId, int $seconds): void
    {
        $this->app->db()->run('UPDATE messages SET created_at = UTC_TIMESTAMP() - INTERVAL ? SECOND WHERE id = ?', [$seconds, $messageId]);
    }

    private function relogin(string $email): void
    {
        $this->cookies = [];
        $this->login($email, 'Motdepasse123');
    }

    /** @return list<array<string,mixed>> */
    private function updates(): array
    {
        return $this->json($this->get('/api/chat/updates', ['since' => '0']))['events'];
    }

    public function testEditWithinWindowThenRefusedAfter(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $m = $this->app->chat()->send($this->u($a), $cid, 'Coulage à 7 h');
        $other = $this->app->chat()->send($this->u($b), $cid, 'Ok');
        $this->age($m['message_id'], 14 * 60);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $r = $this->post("/api/chat/messages/{$m['message_id']}/edit", ['body' => 'Coulage à 8 h']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertNotNull($this->app->db()->value('SELECT edited_at FROM messages WHERE id = ?', [$m['message_id']]));
        $this->assertSame(422, $this->post("/api/chat/messages/{$other['message_id']}/edit", ['body' => 'pirate'])->status);
        $this->age($m['message_id'], 15 * 60 + 1);
        $r = $this->post("/api/chat/messages/{$m['message_id']}/edit", ['body' => 'Coulage à 9 h']);
        $this->assertSame(422, $r->status);
        $this->assertSame('Ce message ne peut plus être modifié.', $this->json($r)['errors']['body']);
        $this->relogin('b@kibarou.test');
        $edited = array_values(array_filter($this->updates(), static fn ($e) => $e['type'] === 'message_edited'));
        $this->assertCount(1, $edited);
        $this->assertSame('Coulage à 8 h', $edited[0]['body']);
        $this->assertSame($m['message_id'], $edited[0]['message_id']);
        $this->assertNotNull($edited[0]['edited_at']);
    }

    public function testDeleteRemovesFilesAndContent(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $img = imagecreatetruecolor(300, 200);
        $tmp = tempnam(sys_get_temp_dir(), 'kbp');
        imagejpeg($img, $tmp);
        $att = $this->app->chat()->uploadAttachment($this->u($a), $cid, ['name' => 'p.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]);
        $m = $this->app->chat()->send($this->u($a), $cid, 'secret du chantier', null, [$att['id']]);
        $this->app->chat()->react($this->u($b), $m['message_id'], '👍');
        $files = [$this->app->chatAttachments()->absolutePath($att['stored_path']), $this->app->chatAttachments()->absolutePath($att['thumb_path'])];
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertSame(200, $this->post("/api/chat/messages/{$m['message_id']}/delete")->status);
        foreach ($files as $f) {
            $this->assertFileDoesNotExist((string) $f);
        }
        $db = $this->app->db();
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM chat_attachments'));
        $this->assertSame(0, (int) $db->value('SELECT COUNT(*) FROM chat_reactions'));
        $this->assertSame('', $db->value('SELECT body FROM messages WHERE id = ?', [$m['message_id']]));
        $this->assertStringNotContainsString('secret', (string) $db->value("SELECT GROUP_CONCAT(details) FROM audit_logs WHERE action = 'chat.message_deleted'"));
        $this->assertSame(1, (int) $db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'chat.message_deleted'"));
        $this->relogin('b@kibarou.test');
        $this->assertSame(404, $this->get("/chat/attachments/{$att['id']}/thumb")->status);
        $events = $this->updates();
        $this->assertContains('message_deleted', array_column($events, 'type'));
        $this->assertStringNotContainsString('secret', json_encode($events, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($events[0]['deleted']);
    }

    public function testReactionToggleAndNoNotification(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $m = $this->app->chat()->send($this->u($a), $cid, 'Photo du coffrage');
        $notifications = (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications');
        $this->login('b@kibarou.test', 'Motdepasse123');
        $r = $this->post("/api/chat/messages/{$m['message_id']}/reactions", ['emoji' => '👍']);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame([['emoji' => '👍', 'count' => 1, 'mine' => true, 'names' => ['Test B']]], $this->json($r)['event']['reactions']);
        $r = $this->post("/api/chat/messages/{$m['message_id']}/reactions", ['emoji' => '👍']);
        $this->assertSame([], $this->json($r)['event']['reactions']);
        $this->assertSame(422, $this->post("/api/chat/messages/{$m['message_id']}/reactions", ['emoji' => '🔥'])->status);
        $this->assertSame($notifications, (int) $this->app->db()->value('SELECT COUNT(*) FROM notifications'));
    }

    public function testActionsDoNotReorderListOrCountUnread(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $m = $this->app->chat()->send($this->u($b), $cid, 'Bonjour');
        $db = $this->app->db();
        $db->run('UPDATE conversations SET last_event_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = ?', [$cid]);
        $before = $db->value('SELECT last_event_at FROM conversations WHERE id = ?', [$cid]);
        $this->app->chat()->react($this->u($a), $m['message_id'], '✅');
        $this->app->chat()->edit($this->u($b), $m['message_id'], 'Bonjour à tous');
        $this->assertSame($before, $db->value('SELECT last_event_at FROM conversations WHERE id = ?', [$cid]));
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertSame(1, $this->json($this->get('/api/chat/updates', ['since' => '0']))['total_unread']);
    }

    public function testLateMemberGetsNothingAboutEarlierMessages(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $d = $this->createUser('d@kibarou.test');
        $g = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $early = $this->app->chat()->send($this->u($a), $g, 'avant ton arrivée');
        $early2 = $this->app->chat()->send($this->u($a), $g, 'autre ancien');
        $this->app->chat()->addMembers($this->u($a), $g, [$d]);
        $this->app->chat()->edit($this->u($a), $early['message_id'], 'avant ton arrivée (corrigé)');
        $this->app->chat()->react($this->u($b), $early['message_id'], '👀');
        $this->app->chat()->delete($this->u($a), $early2['message_id']);
        $this->login('d@kibarou.test', 'Motdepasse123');
        $events = $this->updates();
        $this->assertSame(['member_added'], array_values(array_unique(array_column($events, 'type'))));
        $history = $this->json($this->get("/api/chat/conversations/$g/history"))['events'];
        $this->assertSame(['member_added'], array_values(array_unique(array_column($history, 'type'))));
        $this->assertSame(404, $this->post("/api/chat/messages/{$early['message_id']}/reactions", ['emoji' => '👍'])->status);
    }

    public function testListExcerptForDeletedAndAttachmentOnly(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $img = imagecreatetruecolor(50, 50);
        $tmp = tempnam(sys_get_temp_dir(), 'kbp');
        imagejpeg($img, $tmp);
        $att = $this->app->chat()->uploadAttachment($this->u($a), $cid, ['name' => 'p.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]);
        $m = $this->app->chat()->send($this->u($a), $cid, '', null, [$att['id']]);
        $repo = new \App\Modules\Chat\ChatRepository($this->app->db());
        $this->assertSame('Photo', $repo->conversationsFor($b)[0]['last_body']);
        $this->app->chat()->delete($this->u($a), $m['message_id']);
        $this->assertSame('Message supprimé', $repo->conversationsFor($b)[0]['last_body']);
    }
}
