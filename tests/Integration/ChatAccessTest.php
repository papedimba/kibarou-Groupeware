<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Modules\Chat\ChatRepository;

final class ChatAccessTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function repo(): ChatRepository
    {
        return new ChatRepository($this->app->db());
    }

    /** @return list<string> */
    private function bodies(array $events): array
    {
        return array_values(array_filter(array_column($events, 'body')));
    }

    public function testCreateGroupRules(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $others = [];
        for ($i = 0; $i < 51; $i++) {
            $others[] = $this->createUser("x$i@kibarou.test");
        }
        foreach ([['', [$b, $c]], [str_repeat('n', 81), [$b, $c]], ['Équipe', [$b]], ['Équipe', $others]] as [$name, $ids]) {
            try {
                $this->app->chat()->createGroup($this->u($a), $name, $ids);
                $this->fail('Validation attendue');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $cid = $this->app->chat()->createGroup($this->u($a), 'Chantier Pont', [$b, $c]);
        $this->assertCount(3, $this->repo()->members($cid));
        $this->assertSame(2, (int) $this->app->db()->value("SELECT COUNT(*) FROM chat_events WHERE conversation_id = ? AND type = 'member_added'", [$cid]));
    }

    public function testLateMemberSeesNoEarlierHistory(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $d = $this->createUser('d@kibarou.test');
        $cid = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $d]);
        $this->app->chat()->send($this->u($a), $cid, 'avant');
        $this->app->chat()->addMembers($this->u($a), $cid, [$c]);
        $this->app->chat()->send($this->u($a), $cid, 'après');
        $events = $this->repo()->events($c, 0);
        $this->assertSame(['après'], $this->bodies($events));
        $this->assertSame(['après'], $this->bodies($this->repo()->history($c, $cid, null)));
        $this->assertContains('member_added', array_column($events, 'type'), 'C voit son propre ajout');
        $this->assertSame(['avant', 'après'], $this->bodies($this->repo()->events($b, 0)));
    }

    public function testFormerMemberIsLockedOut(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $cid = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $this->app->chat()->send($this->u($a), $cid, 'un');
        $this->app->chat()->leave($this->u($b), $cid);
        $this->assertSame([], $this->repo()->events($b, 0));
        try {
            $this->app->chat()->send($this->u($b), $cid, 'deux');
            $this->fail('404 attendu');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->expectException(HttpException::class);
        $this->app->chat()->requireMember($b, $cid);
    }

    public function testReAddedMemberRestartsFromReAdd(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $cid = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $this->app->chat()->leave($this->u($b), $cid);
        $this->app->chat()->send($this->u($a), $cid, 'pendant');
        $this->app->chat()->addMembers($this->u($a), $cid, [$b]);
        $this->app->chat()->send($this->u($a), $cid, 'retour');
        $this->assertSame(['retour'], $this->bodies($this->repo()->events($b, 0)));
    }

    public function testMemberCapAndDirectHasNoGroupActions(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->createUser("m$i@kibarou.test");
        }
        $cid = $this->app->chat()->createGroup($this->u($a), 'Plein', $ids);
        $extra = $this->createUser('extra@kibarou.test');
        try {
            $this->app->chat()->addMembers($this->u($a), $cid, [$extra]);
            $this->fail('Plafond attendu');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $direct = $this->app->chat()->directWith($this->u($a), $extra);
        foreach ([fn () => $this->app->chat()->addMembers($this->u($a), $direct, [$ids[0]]), fn () => $this->app->chat()->leave($this->u($a), $direct), fn () => $this->app->chat()->rename($this->u($a), $direct, 'x')] as $call) {
            try {
                $call();
                $this->fail('Validation attendue');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAuditHasNoMessageContent(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $d = $this->createUser('d@kibarou.test');
        $cid = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $d]);
        $this->app->chat()->addMembers($this->u($a), $cid, [$c]);
        $this->app->chat()->send($this->u($a), $cid, 'secret-123');
        $this->assertSame(0, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%secret-123%'"));
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'chat.group_created'"));
    }
}
