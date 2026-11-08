<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;

final class ChatServiceTest extends IntegrationTestCase
{
    private function user(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function rows(string $sql, array $p = []): int
    {
        return (int) $this->app->db()->value($sql, $p);
    }

    public function testDirectWithCreatesOnceAndReturnsSame(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $chat = $this->app->chat();
        $id = $chat->directWith($this->user($a), $b);
        $this->assertSame($id, $chat->directWith($this->user($a), $b));
        $this->assertSame($id, $chat->directWith($this->user($b), $a));
        $this->assertSame(2, $this->rows('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?', [$id]));
        $this->assertSame(1, $this->rows("SELECT COUNT(*) FROM chat_events WHERE conversation_id = ? AND type = 'created'", [$id]));
    }

    public function testDirectConversationIsUniqueEvenOnDuplicateInsert(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $key = min($a, $b) . '-' . max($a, $b);
        $existing = $this->app->db()->insert('conversations', ['type' => 'direct', 'direct_key' => $key, 'created_by' => $a, 'created_at' => now_utc(), 'last_event_at' => now_utc()]);
        $this->assertSame($existing, $this->app->chat()->directWith($this->user($a), $b));
        $this->assertSame(1, $this->rows('SELECT COUNT(*) FROM conversations WHERE direct_key = ?', [$key]));
    }

    public function testCannotWriteToSelfOrUnknown(): void
    {
        $a = $this->createUser('a@kibarou.test');
        foreach ([$a, 999999] as $target) {
            try {
                $this->app->chat()->directWith($this->user($a), $target);
                $this->fail('404 attendu');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->status);
            }
        }
    }

    public function testSendCreatesMessageAndEvent(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->user($a), $b);
        $this->app->db()->run("UPDATE conversations SET last_event_at = '2020-01-01 00:00:00' WHERE id = ?", [$cid]);
        $r = $this->app->chat()->send($this->user($a), $cid, 'Bonjour');
        $this->assertSame('Bonjour', $this->app->db()->value('SELECT body FROM messages WHERE id = ?', [$r['message_id']]));
        $this->assertSame($r['message_id'], (int) $this->app->db()->value("SELECT message_id FROM chat_events WHERE id = ? AND type = 'message'", [$r['event_id']]));
        $this->assertNotSame('2020-01-01 00:00:00', $this->app->db()->value('SELECT last_event_at FROM conversations WHERE id = ?', [$cid]));
    }

    public function testSendValidation(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->user($a), $b);
        foreach (['', "   \n ", str_repeat('x', 4001)] as $body) {
            try {
                $this->app->chat()->send($this->user($a), $cid, $body);
                $this->fail('Validation attendue');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->app->chat()->send($this->user($a), $cid, str_repeat('x', 4000));
        $this->assertSame(1, $this->rows('SELECT COUNT(*) FROM messages'));
    }

    public function testRateLimit(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->user($a), $b);
        for ($i = 0; $i < 30; $i++) {
            $this->app->chat()->send($this->user($a), $cid, "m$i");
        }
        try {
            $this->app->chat()->send($this->user($a), $cid, 'trop');
            $this->fail('429 attendu');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->status);
        }
    }

    public function testCannotSendToDisabledUser(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->user($a), $b);
        $this->app->chat()->send($this->user($a), $cid, 'avant');
        $this->app->db()->update('users', ['status' => 'disabled'], ['id' => $b]);
        try {
            $this->app->chat()->send($this->user($a), $cid, 'après');
            $this->fail('Validation attendue');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ne peut plus recevoir de messages', $e->errors['body']);
        }
        $this->assertSame(1, $this->rows('SELECT COUNT(*) FROM messages WHERE conversation_id = ?', [$cid]));
    }

    public function testNonMemberCannotSend(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $cid = $this->app->chat()->directWith($this->user($a), $b);
        $this->expectExceptionObject(new HttpException(404));
        $this->app->chat()->send($this->user($c), $cid, 'intrus');
    }
}
