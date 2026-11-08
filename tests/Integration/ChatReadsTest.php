<?php

declare(strict_types=1);

namespace Tests\Integration;

final class ChatReadsTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function testReadsOnlyForMembers(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->createUser('c@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($a), $cid, 'bonjour');
        $this->login('c@kibarou.test', 'Motdepasse123');
        $data = json_decode($this->get('/api/chat/updates', ['since' => '0', 'open' => (string) $cid])->body, true);
        $this->assertArrayNotHasKey('reads', $data);
        $this->cookies = [];
        $this->login('a@kibarou.test', 'Motdepasse123');
        $data = json_decode($this->get('/api/chat/updates', ['since' => '0', 'open' => (string) $cid])->body, true);
        $this->assertSame([$b], array_column($data['reads'], 'user_id'));
        $this->assertArrayHasKey('entered_event_id', $data['reads'][0]);
    }

    public function testLateJoinerNotCountedAsReader(): void
    {
        $chef = $this->createUser('chef@kibarou.test', ['chef_service']);
        $x = $this->createUser('x@kibarou.test');
        $y = $this->createUser('y@kibarou.test');
        $z = $this->createUser('z@kibarou.test');
        $cid = $this->app->channels()->create($this->u($chef), 'Chantier', '');
        $this->app->channels()->join($this->u($x), $cid);
        $this->app->channels()->join($this->u($y), $cid);
        $m = $this->app->chat()->send($this->u($chef), $cid, 'Réunion à 9 h');
        $this->app->chat()->markRead($this->u($x), $cid, $m['event_id']);
        $this->app->chat()->markRead($this->u($y), $cid, $m['event_id']);
        $this->app->channels()->join($this->u($z), $cid);
        $this->login('chef@kibarou.test', 'Motdepasse123');
        $body = $this->get("/chat/$cid")->body;
        $this->assertStringContainsString('Vu par 2', $body);
        $this->assertStringNotContainsString('Vu par 3', $body);
    }

    public function testDirectSeenUnderLastReadMessage(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $m1 = $this->app->chat()->send($this->u($a), $cid, 'premier');
        $m2 = $this->app->chat()->send($this->u($a), $cid, 'second');
        $this->app->chat()->markRead($this->u($b), $cid, $m1['event_id']);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $pattern = static fn (int $first, int $second) => '/data-message-id="' . $first . '".*?kb-msg-seen.*?data-message-id="' . $second . '"/s';
        $body = $this->get("/chat/$cid")->body;
        $this->assertSame(1, substr_count($body, 'kb-msg-seen"'));
        $this->assertMatchesRegularExpression($pattern($m1['message_id'], $m2['message_id']), $body);
        $this->app->chat()->markRead($this->u($b), $cid, $m2['event_id']);
        $body = $this->get("/chat/$cid")->body;
        $this->assertDoesNotMatchRegularExpression($pattern($m1['message_id'], $m2['message_id']), $body);
        $this->assertMatchesRegularExpression('/data-message-id="' . $m2['message_id'] . '".*?kb-msg-seen/s', $body);
    }
}
