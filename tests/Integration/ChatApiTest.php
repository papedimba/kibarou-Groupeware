<?php

declare(strict_types=1);

namespace Tests\Integration;

final class ChatApiTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function sessionId(): string
    {
        return hash('sha256', $this->cookies['kb_session'] ?? '');
    }

    /** @return array<string,mixed> */
    private function json(\App\Core\Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    private function agePastSession(): string
    {
        $past = gmdate('Y-m-d H:i:s', time() - 600);
        $this->app->db()->run('UPDATE sessions SET last_activity_at = ? WHERE id = ?', [$past, $this->sessionId()]);
        return $past;
    }

    private function lastActivity(): string
    {
        return (string) $this->app->db()->value('SELECT last_activity_at FROM sessions WHERE id = ?', [$this->sessionId()]);
    }

    public function testUpdatesReturnsOnlyMyEventsAfterSince(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $mine = $this->app->chat()->directWith($this->u($a), $b);
        $other = $this->app->chat()->directWith($this->u($b), $c);
        $first = $this->app->chat()->send($this->u($b), $mine, 'premier');
        $this->app->chat()->send($this->u($b), $mine, 'deuxième');
        $this->app->chat()->send($this->u($c), $other, 'pas pour A');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $r = $this->get('/api/chat/updates', ['since' => (string) $first['event_id']]);
        $this->assertSame(200, $r->status);
        $data = $this->json($r);
        $this->assertSame(['deuxième'], array_column($data['events'], 'body'));
        $this->assertSame($mine, $data['events'][0]['conversation_id']);
        $this->assertSame('Test B', $data['events'][0]['sender_name']);
        $this->assertSame(2, $data['unread'][(string) $mine]);
        $this->assertSame(2, $data['total_unread']);
    }

    public function testSendJsonRequiresMembershipAndCsrf(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->createUser('c@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertSame(419, $this->post("/api/chat/conversations/$cid/messages", ['body' => 'x'], false)->status);
        $r = $this->post("/api/chat/conversations/$cid/messages", ['body' => 'Bonjour']);
        $this->assertSame(200, $r->status);
        $this->assertSame('Bonjour', $this->json($r)['event']['body']);
        $this->assertGreaterThan(0, $this->json($r)['event']['id']);
        $r = $this->post("/api/chat/conversations/$cid/messages", ['body' => str_repeat('a', 4001)]);
        $this->assertSame(422, $r->status);
        $this->assertArrayHasKey('body', $this->json($r)['errors']);

        $this->cookies = [];
        $this->login('c@kibarou.test', 'Motdepasse123');
        $this->assertSame(404, $this->post("/api/chat/conversations/$cid/messages", ['body' => 'intrus'])->status);
        $this->assertSame(404, $this->get("/api/chat/conversations/$cid/history")->status);
    }

    public function testHistoryPaginates(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        // Envoi direct en base : la limite de débit n'est pas l'objet de ce test.
        $db = $this->app->db();
        for ($i = 1; $i <= 60; $i++) {
            $mid = $db->insert('messages', ['conversation_id' => $cid, 'sender_id' => $b, 'body' => "m$i", 'created_at' => now_utc()]);
            $db->insert('chat_events', ['conversation_id' => $cid, 'type' => 'message', 'message_id' => $mid, 'actor_id' => $b, 'created_at' => now_utc()]);
        }
        $this->login('a@kibarou.test', 'Motdepasse123');
        $page = $this->json($this->get("/api/chat/conversations/$cid/history"))['events'];
        $this->assertCount(50, $page);
        $this->assertSame('m11', $page[0]['body']);
        $older = $this->json($this->get("/api/chat/conversations/$cid/history", ['before' => (string) $page[0]['id']]))['events'];
        $this->assertSame(array_map(static fn ($i) => "m$i", range(1, 10)), array_column($older, 'body'));
    }

    public function testPollDoesNotExtendSessionButSendDoes(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $token = $this->csrfToken();
        $past = $this->agePastSession();
        $this->assertSame(200, $this->get('/api/chat/updates', ['since' => '0'])->status);
        $this->assertSame($past, $this->lastActivity());
        $this->assertSame(200, $this->post("/api/chat/conversations/$cid/messages", ['body' => 'actif', '_csrf' => $token])->status);
        $this->assertNotSame($past, $this->lastActivity());
    }

    public function testReadIsPassiveUnlessActive(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $sent = $this->app->chat()->send($this->u($b), $cid, 'lu ?');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $token = $this->csrfToken();
        $past = $this->agePastSession();
        $r = $this->post("/api/chat/conversations/$cid/read", ['event_id' => (string) $sent['event_id'], '_csrf' => $token]);
        $this->assertSame(['ok' => true], $this->json($r));
        $this->assertSame($past, $this->lastActivity());
        $this->assertSame(0, $this->json($this->get('/api/chat/updates', ['since' => '0']))['total_unread']);
        $this->post("/api/chat/conversations/$cid/read", ['event_id' => (string) $sent['event_id'], 'active' => '1', '_csrf' => $token]);
        $this->assertNotSame($past, $this->lastActivity());
    }

    public function testNotificationsPollIncludesChatUnread(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($b), $cid, 'coucou');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertSame(1, $this->json($this->get('/api/notifications'))['chat_unread']);
    }
}
