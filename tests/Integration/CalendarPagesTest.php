<?php

declare(strict_types=1);

namespace Tests\Integration;

final class CalendarPagesTest extends IntegrationTestCase
{
    public function testWeekMonthListRender(): void
    {
        $this->createUser('a@kibarou.test');
        $this->login('a@kibarou.test', 'Motdepasse123');
        $form = $this->get('/calendar/events/new');
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="title"', $form->body);
        $day = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $r = $this->post('/calendar/events', ['title' => 'Réunion de chantier', 'all_day' => '0', 'start' => "$day 08:00", 'end' => "$day 09:00",
            'location' => 'Base vie', 'description' => '', 'reminder' => '15']);
        $id = (int) $this->app->db()->value('SELECT MAX(id) FROM events');
        $this->assertRedirect($r, "/calendar/events/$id");
        $this->post('/calendar/events', ['title' => 'Inventaire du dépôt', 'all_day' => '1', 'start' => $day, 'end' => $day, 'reminder' => '']);
        foreach (['week', 'month', 'list'] as $view) {
            $body = $this->get('/calendar', ['view' => $view, 'date' => $day])->body;
            $this->assertStringContainsString('Réunion de chantier', $body, $view);
            $this->assertStringContainsString('Inventaire du dépôt', $body, $view);
        }
        $this->assertMatchesRegularExpression('/kb-cal-allday[^<]*>[^<]*<[^>]*>?[^<]*Inventaire du dépôt|kb-cal-allday.*?Inventaire du dépôt/s', $this->get('/calendar', ['view' => 'week', 'date' => $day])->body);
        $this->assertStringContainsString('Réunion de chantier', $this->get("/calendar/events/$id")->body);
    }
}
