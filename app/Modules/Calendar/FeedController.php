<?php

declare(strict_types=1);

namespace App\Modules\Calendar;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Export iCalendar : un évènement (participants), et l'abonnement personnel par lien secret (hors session). */
final class FeedController extends Controller
{
    private const HITS_PER_HOUR = 60;

    public function settings(Request $request): Response
    {
        $me = (int) $this->user()['id'];
        return $this->view('calendar/feed_settings', [
            'currentPath' => '/profile',
            'hasFeed' => $this->app->db()->value('SELECT 1 FROM calendar_feeds WHERE user_id = ?', [$me]) !== null,
            // Lien montré une seule fois, juste après sa création (seule son empreinte est conservée).
            'feedUrl' => $this->app->session()->getFlash('feed_url'),
        ]);
    }

    public function generate(Request $request): Response
    {
        $me = (int) $this->user()['id'];
        $token = bin2hex(random_bytes(32));
        $this->app->db()->run(
            'INSERT INTO calendar_feeds (user_id, token_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), created_at = VALUES(created_at)',
            [$me, hash('sha256', $token)],
        );
        $this->app->audit()->log($me, 'calendar.feed_generated', 'user', $me);
        $this->app->session()->flash('feed_url', rtrim((string) $this->app->config('app.url'), '/') . url('/calendar/feed/' . $token . '.ics'));
        return $this->redirect('/profile/calendar', 'Nouveau lien d\'abonnement créé : l\'ancien ne fonctionne plus.');
    }

    public function feed(Request $request): Response
    {
        $hash = hash('sha256', (string) ($request->params['token'] ?? ''));
        // Compte désactivé ou sans accès au calendrier : le lien ne donne plus rien.
        $owner = $this->app->db()->fetch(
            "SELECT u.* FROM calendar_feeds f JOIN users u ON u.id = f.user_id AND u.status = 'active' AND u.deleted_at IS NULL WHERE f.token_hash = ?",
            [$hash],
        );
        if ($owner === null || !$this->app->rbac()->can($owner, 'calendar.use')) {
            throw new HttpException(404);
        }
        $userId = (int) $owner['id'];
        $db = $this->app->db();
        $db->run('DELETE FROM calendar_feed_hits WHERE token_hash = ? AND hit_at < UTC_TIMESTAMP() - INTERVAL 1 HOUR', [$hash]);
        if ((int) $db->value('SELECT COUNT(*) FROM calendar_feed_hits WHERE token_hash = ?', [$hash]) >= self::HITS_PER_HOUR) {
            throw new HttpException(429, 'Trop de demandes pour ce lien. Réessayez plus tard.');
        }
        $db->insert('calendar_feed_hits', ['token_hash' => $hash, 'hit_at' => now_utc()]);
        $tz = $this->app->events()->timezone();
        $from = new \DateTimeImmutable('-30 days', $tz);
        $to = new \DateTimeImmutable('+365 days', $tz);
        $events = array_values(array_filter(
            (new EventRepository($db))->forUser((int) $userId, $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $from->format('Y-m-d'), $to->format('Y-m-d')),
            static fn ($e) => $e['response'] !== 'no',
        ));
        return $this->ics($events, 'kibarou.ics');
    }

    public function download(Request $request): Response
    {
        $event = (new EventRepository($this->app->db()))->visibleEvent((int) $this->user()['id'], $this->id($request)) ?? throw new HttpException(404);
        return $this->ics([$event], 'evenement-' . (int) $event['id'] . '.ics');
    }

    /** @param list<array<string,mixed>> $events */
    private function ics(array $events, string $filename): Response
    {
        $host = (string) (parse_url((string) $this->app->config('app.url'), PHP_URL_HOST) ?: 'kibarou.local');
        $response = new Response((new IcsWriter())->calendar($events, $host));
        $response->headers['Content-Type'] = 'text/calendar; charset=utf-8';
        $response->headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        $response->headers['Cache-Control'] = 'private, no-store';
        return $response;
    }
}
