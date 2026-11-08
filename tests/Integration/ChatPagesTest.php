<?php

declare(strict_types=1);

namespace Tests\Integration;

final class ChatPagesTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function testListAndEmptyState(): void
    {
        $this->createUser('a@kibarou.test');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $body = $this->get('/chat')->body;
        $this->assertStringContainsString('Aucune conversation pour l\'instant.', $body);
        $this->assertStringContainsString('Nouvelle conversation', $body);
    }

    public function testWriteFromDirectoryOpensConversation(): void
    {
        $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertStringContainsString('href="/chat/with/' . $b . '"', $this->get("/directory/$b")->body);
        $r = $this->get("/chat/with/$b");
        $cid = (int) $this->app->db()->value("SELECT id FROM conversations WHERE type = 'direct'");
        $this->assertRedirect($r, "/chat/$cid");
    }

    public function testSendWithoutJavascript(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->post("/chat/$cid", ['body' => 'Bonjour depuis le chantier']), "/chat/$cid");
        $this->assertStringContainsString('Bonjour depuis le chantier', $this->get("/chat/$cid")->body);
    }

    public function testMessageIsEscapedAndOnlyHttpLinksAreClickable(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($b), $cid, '<script>alert(1)</script> https://kibarou.test/plan?a=1&b=2 javascript:alert(1)');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $body = $this->get("/chat/$cid")->body;
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('<a href="https://kibarou.test/plan?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">', $body);
        $this->assertStringNotContainsString('href="javascript:', $body);
    }

    public function testNonMemberGets404(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->createUser('c@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->login('c@kibarou.test', 'Motdepasse123');
        $this->assertSame(404, $this->get("/chat/$cid")->status);
        $this->assertSame(404, $this->post("/chat/$cid", ['body' => 'intrus'])->status);
    }

    public function testCreateGroupFromForm(): void
    {
        $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertSame(200, $this->get('/chat/new')->status);
        $r = $this->post('/chat/new', ['name' => 'Chantier Pont', 'members' => [(string) $b, (string) $c]]);
        $cid = (int) $this->app->db()->value("SELECT id FROM conversations WHERE type = 'group'");
        $this->assertRedirect($r, "/chat/$cid");
        $this->assertStringContainsString('Chantier Pont', $this->get("/chat/$cid")->body);
    }

    public function testMenuShowsUnreadTotal(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($b), $cid, 'un');
        $this->app->chat()->send($this->u($b), $cid, 'deux');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertMatchesRegularExpression('/data-chat-badge[^>]*>2</', $this->get('/')->body);
    }
}
