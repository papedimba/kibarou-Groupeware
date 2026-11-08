<?php

declare(strict_types=1);

namespace Tests\Integration;

final class EventAccessTest extends IntegrationTestCase
{
    public function testNonParticipantSeesNothing(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $this->createUser('c@kibarou.test');
        $day = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $id = $this->app->events()->create($this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$a]), ['title' => 'Entretien annuel confidentiel',
            'all_day' => '0', 'start' => "$day 09:00", 'end' => "$day 10:00", 'location' => 'Bureau DG', 'description' => '', 'is_private' => '0',
            'reminder' => '', 'participants' => [], 'departments' => []]);
        $this->login('c@kibarou.test', 'Motdepasse123');
        $this->assertSame(404, $this->get("/calendar/events/$id")->status);
        foreach (['week', 'month', 'list'] as $view) {
            $this->assertStringNotContainsString('Entretien annuel', $this->get('/calendar', ['view' => $view, 'date' => $day])->body);
        }
    }
}
