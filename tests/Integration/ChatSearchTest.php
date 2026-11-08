<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Chat\ChatRepository;

final class ChatSearchTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function repo(): ChatRepository
    {
        return new ChatRepository($this->app->db());
    }

    public function testSearchRespectsVisibility(): void
    {
        $a = $this->createUser('a@kibarou.test', ['chef_service']);
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $outsider = $this->createUser('d@kibarou.test');
        $late = $this->createUser('e@kibarou.test');
        $g = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $this->app->chat()->send($this->u($a), $g, 'Ferraillage du radier terminé');
        $gone = $this->app->chat()->send($this->u($a), $g, 'radier à reprendre');
        $this->app->chat()->delete($this->u($a), $gone['message_id']);
        $this->app->chat()->addMembers($this->u($a), $g, [$late]);
        $salon = $this->app->channels()->create($this->u($a), 'Chantier', '');
        $this->app->chat()->send($this->u($a), $salon, 'Radier du chantier coulé');
        $this->app->channels()->join($this->u($late), $salon);
        $this->assertSame(1, count($this->repo()->search($b, 'radier')), 'membre du groupe, message supprimé exclu');
        $this->assertSame([], $this->repo()->search($outsider, 'radier'), 'non-membre');
        $found = $this->repo()->search($late, 'radier');
        $this->assertSame(['Radier du chantier coulé'], array_map(static fn ($r) => strip_tags($r['excerpt_html']), $found), 'arrivé tard : groupe non, salon oui');
    }

    public function testWildcardsAreLiteralAndFilenamesMatch(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($a), $cid, '500 sacs livrés');
        $this->app->chat()->send($this->u($a), $cid, 'Avancement 50% du béton');
        $this->app->chat()->send($this->u($a), $cid, 'lot_3 validé');
        $this->app->chat()->send($this->u($a), $cid, 'lotX3 refusé');
        $this->assertSame(['Avancement 50% du béton'], array_map(static fn ($r) => strip_tags($r['excerpt_html']), $this->repo()->search($b, '50%')));
        $this->assertSame(['lot_3 validé'], array_map(static fn ($r) => strip_tags($r['excerpt_html']), $this->repo()->search($b, 'lot_3')));
        $pdf = tempnam(sys_get_temp_dir(), 'kbf');
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $att = $this->app->chat()->uploadAttachment($this->u($a), $cid, ['name' => 'Suivi béton.pdf', 'tmp_name' => $pdf, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pdf)]);
        $this->app->chat()->send($this->u($a), $cid, 'voir pièce', null, [$att['id']]);
        $hit = $this->repo()->search($b, 'suivi');
        $this->assertCount(1, $hit);
        $this->assertSame('file', $hit[0]['matched']);
        $this->assertStringContainsString('<mark>Suivi</mark>', $hit[0]['excerpt_html']);
    }

    public function testExcerptIsEscapedAndHighlighted(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->app->chat()->send($this->u($a), $cid, '<b>dalle</b> coulée');
        $hit = $this->repo()->search($b, 'dalle')[0];
        $this->assertStringContainsString('&lt;b&gt;<mark>dalle</mark>&lt;/b&gt;', $hit['excerpt_html']);
        $this->login('b@kibarou.test', 'Motdepasse123');
        $page = $this->get('/chat/search', ['q' => 'dalle'])->body;
        $this->assertStringContainsString('&lt;b&gt;<mark>dalle</mark>&lt;/b&gt;', $page);
        $this->assertStringContainsString('/chat/' . $cid . '?around=' . $hit['message_id'], $page);
        $this->assertStringContainsString('Tapez au moins 2 caractères.', $this->get('/chat/search', ['q' => 'd'])->body);
        $this->assertStringContainsString('Aucun message ne correspond à « zzz ».', $this->get('/chat/search', ['q' => 'zzz'])->body);
    }

    public function testAroundOpensOnMessage(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $db = $this->app->db();
        $ids = [];
        for ($i = 1; $i <= 80; $i++) {
            $mid = $db->insert('messages', ['conversation_id' => $cid, 'sender_id' => $a, 'body' => "message n°$i", 'created_at' => now_utc()]);
            $db->insert('chat_events', ['conversation_id' => $cid, 'type' => 'message', 'message_id' => $mid, 'actor_id' => $a, 'created_at' => now_utc()]);
            $ids[$i] = $mid;
        }
        $this->login('b@kibarou.test', 'Motdepasse123');
        $page = $this->get("/chat/$cid", ['around' => (string) $ids[10]])->body;
        $this->assertMatchesRegularExpression('/class="kb-msg[^"]*is-target[^"]*"[^>]*data-message-id="' . $ids[10] . '"/', $page);
        $this->assertStringContainsString('message n°1<', $page);
        $this->assertStringContainsString('message n°35<', $page);
        $this->assertStringNotContainsString('data-message-id="' . $ids[80] . '"', $page);
        $this->assertStringContainsString('Afficher les messages suivants', $page);
    }
}
