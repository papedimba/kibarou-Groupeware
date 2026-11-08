<?php

declare(strict_types=1);

namespace Tests\Integration;

/** Correctifs de la relecture finale du lot 4a et preuves HTTP des points sensibles. */
final class ChatReviewFixesTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function testRenameEventShowsNewName(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $g = $this->app->chat()->createGroup($this->u($a), 'Ancien', [$b, $c]);
        $this->app->chat()->rename($this->u($b), $g, 'Chantier Pont');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('Test B a renommé le groupe en « Chantier Pont »', html_entity_decode($this->get("/chat/$g")->body));
        $events = json_decode($this->get("/api/chat/conversations/$g/history")->body, true)['events'];
        $this->assertSame('Chantier Pont', end($events)['detail']);
    }

    public function testOpeningDirectWithoutMessageDoesNotShowInOtherList(): void
    {
        $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->get("/chat/with/$b");
        $this->assertStringContainsString('Test B', $this->get('/chat')->body, 'visible pour celui qui l\'a ouverte');
        $this->cookies = [];
        $this->login('b@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('Aucune conversation pour l\'instant.', $this->get('/chat')->body);
        // B écrit à son tour : la conversation existante s'ouvre normalement.
        $r = $this->get('/chat/with/' . (int) $this->app->db()->value("SELECT id FROM users WHERE email = 'a@kibarou.test'"));
        $this->assertSame(200, $this->get((string) $r->headers['Location'])->status);
    }

    public function testLateMemberHistoryApiHasNothingEarlier(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $d = $this->createUser('d@kibarou.test');
        $g = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $this->app->chat()->send($this->u($a), $g, 'secret avant');
        $this->app->chat()->addMembers($this->u($a), $g, [$d]);
        $this->app->chat()->send($this->u($a), $g, 'après');
        $this->login('d@kibarou.test', 'Motdepasse123');
        $bodies = array_column(json_decode($this->get("/api/chat/conversations/$g/history")->body, true)['events'], 'body');
        $this->assertNotContains('secret avant', $bodies);
        $this->assertContains('après', $bodies);
        $this->assertStringNotContainsString('secret avant', $this->get("/chat/$g")->body);
    }

    public function testFormerMemberCannotReadOrMarkRead(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $g = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $sent = $this->app->chat()->send($this->u($a), $g, 'bonjour');
        $this->app->chat()->leave($this->u($c), $g);
        $this->login('c@kibarou.test', 'Motdepasse123');
        $this->assertSame(404, $this->get("/api/chat/conversations/$g/history")->status);
        $this->assertSame(404, $this->post("/api/chat/conversations/$g/read", ['event_id' => (string) $sent['event_id']])->status);
        $this->assertSame(0, (int) $this->app->db()->value('SELECT last_read_event_id FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$g, $c]));
    }

    public function testHistoryStaysReadableWhenRecipientDisabled(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($b), $cid, 'avant le départ');
        $this->app->db()->update('users', ['status' => 'disabled'], ['id' => $b]);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $r = $this->get("/chat/$cid");
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('avant le départ', $r->body);
    }

    public function testNameFieldsMatchServerLimit(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $g = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('maxlength="80"', $this->get('/chat/new')->body);
        $this->assertStringNotContainsString('maxlength="100"', $this->get("/chat/$g")->body);
    }
}
