<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ValidationException;
use App\Modules\Chat\ChatRepository;
use App\Modules\Organization\OrganizationService;
use App\Modules\Users\UserRepository;
use App\Modules\Users\UserService;

final class ChannelSyncTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    private function dept(string $name, int $active = 1): int
    {
        return $this->app->db()->insert('departments', ['name' => $name, 'is_active' => $active, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    }

    private function salon(int $deptId): ?array
    {
        return $this->app->db()->fetch("SELECT * FROM conversations WHERE type = 'department' AND department_id = ?", [$deptId]);
    }

    /** @return list<int> */
    private function members(int $cid): array
    {
        $ids = array_map('intval', array_column($this->app->db()->fetchAll('SELECT user_id FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL ORDER BY user_id', [$cid]), 'user_id'));
        return $ids;
    }

    private function users(): UserService
    {
        return new UserService($this->app->db(), new UserRepository($this->app->db()), $this->app->rbac(), $this->app->audit(), $this->app->passwordResets(),
            10, 'http://test', $this->app->notifier(), $this->app->channels());
    }

    public function testServiceSalonCreatedWithMembers(): void
    {
        $trv = $this->dept('Travaux');
        $etu = $this->dept('Études');
        $a = $this->createUser('a@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $trv]);
        $b = $this->createUser('b@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $trv]);
        $c = $this->createUser('c@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $etu]);
        $this->createUser('sansdroit@kibarou.test', [], 'Motdepasse123', ['department_id' => $trv]);
        $this->app->channels()->syncDepartments();
        $this->assertSame('Travaux', $this->salon($trv)['name']);
        $this->assertSame([$a, $b], $this->members((int) $this->salon($trv)['id']));
        $this->assertSame([$c], $this->members((int) $this->salon($etu)['id']));
        $this->assertSame(0, (int) $this->app->db()->value('SELECT MAX(notify) FROM conversation_members WHERE conversation_id = ?', [$this->salon($trv)['id']]));
        $this->assertSame(0, (new ChatRepository($this->app->db()))->unreadTotal($a));
    }

    public function testTransferMovesBetweenSalonsImmediately(): void
    {
        $trv = $this->dept('Travaux');
        $etu = $this->dept('Études');
        $admin = $this->createUser('admin@kibarou.test', ['admin']);
        $a = $this->createUser('a@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $trv]);
        $this->app->channels()->syncDepartments();
        $roles = array_map('intval', array_column($this->app->db()->fetchAll('SELECT role_id FROM user_roles WHERE user_id = ?', [$a]), 'role_id'));
        $this->users()->update($a, ['email' => 'a@kibarou.test', 'first_name' => 'Test', 'last_name' => 'A', 'department_id' => (string) $etu], $roles, $this->u($admin));
        $this->assertSame([], $this->members((int) $this->salon($trv)['id']));
        $this->assertSame([$a], $this->members((int) $this->salon($etu)['id']));
    }

    public function testCronCatchesDirectDatabaseChange(): void
    {
        $trv = $this->dept('Travaux');
        $etu = $this->dept('Études');
        $a = $this->createUser('a@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $trv]);
        $this->app->channels()->syncDepartments();
        $this->app->db()->update('users', ['department_id' => $etu], ['id' => $a]);
        $this->app->channels()->syncDepartments();
        $this->assertSame([$a], $this->members((int) $this->salon($etu)['id']));
        $this->assertSame([], $this->members((int) $this->salon($trv)['id']));
        $this->assertSame(['created' => 0, 'added' => 0, 'removed' => 0], $this->app->channels()->syncDepartments());
    }

    public function testDisabledUserRemovedAndServiceDeactivationArchives(): void
    {
        $trv = $this->dept('Travaux');
        $admin = $this->createUser('admin@kibarou.test', ['admin']);
        $a = $this->createUser('a@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $trv]);
        $this->app->channels()->syncDepartments();
        $this->users()->setStatus($a, false, $this->u($admin));
        $this->assertSame([], $this->members((int) $this->salon($trv)['id']));
        $org = new OrganizationService($this->app->db(), $this->app->audit(), $this->app->channels());
        $org->saveDepartment($trv, ['name' => 'Travaux', 'is_active' => '0'], $this->u($admin));
        $this->assertNotNull($this->salon($trv)['archived_at']);
        $org->saveDepartment($trv, ['name' => 'Travaux', 'is_active' => '1'], $this->u($admin));
        $this->assertNull($this->salon($trv)['archived_at']);
    }

    public function testCannotLeaveOrManageServiceSalon(): void
    {
        $trv = $this->dept('Travaux');
        $admin = $this->createUser('admin@kibarou.test', ['admin'], 'Motdepasse123', ['department_id' => $trv]);
        $this->app->channels()->syncDepartments();
        $cid = (int) $this->salon($trv)['id'];
        foreach ([fn () => $this->app->channels()->leave($this->u($admin), $cid), fn () => $this->app->channels()->update($this->u($admin), $cid, 'X', ''), fn () => $this->app->channels()->archive($this->u($admin), $cid)] as $i => $fn) {
            try {
                $fn();
                $this->fail("Action $i acceptée à tort");
            } catch (ValidationException $e) {
                $this->assertContains(array_values($e->errors)[0], ['On ne peut pas quitter le salon de son service.', 'Le salon d\'un service suit l\'annuaire.']);
            }
        }
    }

    public function testRenamedServiceRenamesSalon(): void
    {
        $trv = $this->dept('Travaux');
        $admin = $this->createUser('admin@kibarou.test', ['admin']);
        $this->app->channels()->syncDepartments();
        (new OrganizationService($this->app->db(), $this->app->audit(), $this->app->channels()))->saveDepartment($trv, ['name' => 'Travaux et chantiers', 'is_active' => '1'], $this->u($admin));
        $salon = $this->salon($trv);
        $this->assertSame('Travaux et chantiers', $salon['name']);
        $this->assertSame('Travaux et chantiers', $this->app->db()->value("SELECT detail FROM chat_events WHERE conversation_id = ? AND type = 'renamed'", [$salon['id']]));
    }

    public function testLongServiceNameDoesNotBreakSync(): void
    {
        $long = str_repeat('Direction des études techniques ', 4);
        $first = $this->dept(trim($long));
        $other = $this->dept('Travaux');
        $a = $this->createUser('a@kibarou.test', ['collaborateur'], 'Motdepasse123', ['department_id' => $other]);
        $this->app->channels()->syncDepartments();
        $this->assertSame(mb_substr(trim($long), 0, 80), $this->salon($first)['name']);
        $this->assertSame([$a], $this->members((int) $this->salon($other)['id']), 'les services suivants sont synchronisés');
        $this->app->db()->update('departments', ['name' => trim($long) . ' bis'], ['id' => $first]);
        $this->assertSame(['created' => 0, 'added' => 0, 'removed' => 0], $this->app->channels()->syncDepartments(), 'nom identique une fois tronqué : rien ne change');
    }
}
