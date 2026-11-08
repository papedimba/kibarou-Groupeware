<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;

final class ChannelServiceTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function outcome(callable $fn): int|string
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return $e->status;
        } catch (ValidationException $e) {
            return (string) array_values($e->errors)[0];
        }
        return 0;
    }

    public function testCreateJoinSeeFullHistoryNoUnreadFlood(): void
    {
        $chef = $this->createUser('chef@kibarou.test', ['chef_service']);
        $col = $this->createUser('col@kibarou.test');
        $cid = $this->app->channels()->create($this->u($chef), 'Chantier Pont', 'Pont de Sotuba');
        foreach (['un', 'deux', 'trois'] as $t) {
            $this->app->chat()->send($this->u($chef), $cid, $t);
        }
        $this->app->channels()->join($this->u($col), $cid);
        $repo = new \App\Modules\Chat\ChatRepository($this->app->db());
        $bodies = array_column(array_filter($repo->history($col, $cid, null), static fn ($e) => $e['type'] === 'message'), 'body');
        $this->assertSame(['un', 'deux', 'trois'], array_values($bodies));
        $this->assertSame(0, $repo->unreadTotal($col));
        $this->assertSame(0, (int) $this->app->db()->value('SELECT notify FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$cid, $col]));
        $this->assertSame(403, $this->outcome(fn () => $this->app->channels()->create($this->u($col), 'Interdit', '')));
    }

    public function testManageRights(): void
    {
        $chefA = $this->createUser('a@kibarou.test', ['chef_service']);
        $chefB = $this->createUser('b@kibarou.test', ['chef_service']);
        $dir = $this->createUser('dir@kibarou.test', ['directeur']);
        $admin = $this->createUser('admin@kibarou.test', ['admin']);
        $cid = $this->app->channels()->create($this->u($chefA), 'Chantier A', '');
        $this->assertSame(403, $this->outcome(fn () => $this->app->channels()->update($this->u($chefB), $cid, 'Pris', '')));
        $this->assertSame(403, $this->outcome(fn () => $this->app->channels()->archive($this->u($chefB), $cid)));
        $this->assertSame(0, $this->outcome(fn () => $this->app->channels()->update($this->u($dir), $cid, 'Chantier A (phase 2)', '')));
        $this->assertSame(0, $this->outcome(fn () => $this->app->channels()->archive($this->u($admin), $cid)));
        $this->assertSame(0, $this->outcome(fn () => $this->app->channels()->unarchive($this->u($chefA), $cid)));
        $this->assertSame('Chantier A (phase 2)', $this->app->db()->value('SELECT name FROM conversations WHERE id = ?', [$cid]));
    }

    public function testArchivedRefusesWritesButReadableAndJoinable(): void
    {
        $chef = $this->createUser('chef@kibarou.test', ['chef_service']);
        $col = $this->createUser('col@kibarou.test');
        $cid = $this->app->channels()->create($this->u($chef), 'Chantier fini', '');
        $m = $this->app->chat()->send($this->u($chef), $cid, 'Réception des travaux faite');
        $this->app->channels()->archive($this->u($chef), $cid);
        $archived = 'Ce salon est archivé : il n\'accepte plus de messages.';
        $this->assertSame($archived, $this->outcome(fn () => $this->app->chat()->send($this->u($chef), $cid, 'encore')));
        $this->assertSame($archived, $this->outcome(fn () => $this->app->chat()->react($this->u($chef), $m['message_id'], '👍')));
        $this->assertSame($archived, $this->outcome(fn () => $this->app->chat()->edit($this->u($chef), $m['message_id'], 'modifié')));
        $this->assertSame($archived, $this->outcome(fn () => $this->app->chat()->delete($this->u($chef), $m['message_id'])));
        $this->assertSame($archived, $this->outcome(fn () => $this->app->chat()->uploadAttachment($this->u($chef), $cid, ['name' => 'x.pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE])));
        $this->assertNotContains($cid, array_column($this->app->channels()->list($this->u($col), false), 'id'));
        $this->assertContains($cid, array_column($this->app->channels()->list($this->u($col), true), 'id'));
        $this->app->channels()->join($this->u($col), $cid);
        $this->login('col@kibarou.test', 'Motdepasse123');
        $body = $this->get("/chat/$cid")->body;
        $this->assertStringContainsString('Réception des travaux faite', $body);
        $this->assertStringContainsString('Salon archivé', $body);
    }

    public function testNameUniqueAmongOpenChannels(): void
    {
        $chef = $this->createUser('chef@kibarou.test', ['chef_service']);
        $first = $this->app->channels()->create($this->u($chef), 'Sécurité', '');
        $this->assertSame('Un salon porte déjà ce nom.', $this->outcome(fn () => $this->app->channels()->create($this->u($chef), 'Sécurité', '')));
        $this->app->channels()->archive($this->u($chef), $first);
        $this->assertSame(0, $this->outcome(fn () => $this->app->channels()->create($this->u($chef), 'Sécurité', '')));
    }

    public function testChannelMessageNotifiesOnlyOptedIn(): void
    {
        $chef = $this->createUser('chef@kibarou.test', ['chef_service']);
        $quiet = $this->createUser('quiet@kibarou.test');
        $loud = $this->createUser('loud@kibarou.test');
        $cid = $this->app->channels()->create($this->u($chef), 'Matériel', '');
        $this->app->channels()->join($this->u($quiet), $cid);
        $this->app->channels()->join($this->u($loud), $cid);
        $this->app->channels()->setNotify($this->u($loud), $cid, true);
        $this->app->chat()->send($this->u($chef), $cid, 'Pelleteuse disponible lundi');
        $this->assertSame([$loud], array_map('intval', $this->app->db()->fetchAll("SELECT user_id FROM notifications WHERE type = 'chat.message'") ? array_column($this->app->db()->fetchAll("SELECT user_id FROM notifications WHERE type = 'chat.message'"), 'user_id') : []));
    }

    public function testPagesRender(): void
    {
        $chef = $this->createUser('chef@kibarou.test', ['chef_service']);
        $this->createUser('col@kibarou.test');
        $cid = $this->app->channels()->create($this->u($chef), 'Chantier Pont', 'Pont de Sotuba');
        $this->login('col@kibarou.test', 'Motdepasse123');
        $list = $this->get('/chat/channels')->body;
        $this->assertStringContainsString('Chantier Pont', $list);
        $this->assertStringContainsString('Rejoindre', $list);
        $this->assertRedirect($this->post("/chat/channels/$cid/join"), "/chat/$cid");
        $page = $this->get("/chat/$cid")->body;
        $this->assertStringContainsString('Quitter le salon', $page);
        $this->assertStringContainsString('Alertes', $page);
        $this->assertRedirect($this->post("/chat/$cid/notify", ['on' => '1']), "/chat/$cid");
        $this->assertSame(1, (int) $this->app->db()->value('SELECT notify FROM conversation_members WHERE conversation_id = ? AND user_id = (SELECT id FROM users WHERE email = ?)', [$cid, 'col@kibarou.test']));
    }
}
