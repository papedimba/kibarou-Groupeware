<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Modules\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests de bout en bout sans serveur HTTP : chaque requête traverse App::handle()
 * (routeur, middlewares, contrôleurs, vues) avec un vrai MariaDB/MySQL.
 * Chaque test tourne dans une transaction annulée à la fin.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected App $app;
    protected Router $router;
    /** @var array<string,string> */
    protected array $cookies = [];

    protected function setUp(): void
    {
        $this->app = test_app();
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/routes/web.php')($this->router);
        $this->app->db()->pdo()->beginTransaction();
        $this->cookies = [];
    }

    protected function tearDown(): void
    {
        $pdo = $this->app->db()->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /** @param array<string,mixed> $query */
    protected function get(string $path, array $query = [], string $ip = '10.0.0.1'): Response
    {
        return $this->send(new Request('GET', $path, $query, [], $this->cookies, [], $this->server($ip)));
    }

    /** @param array<string,mixed> $data Ajoute le jeton CSRF courant sauf si $withCsrf = false. */
    protected function post(string $path, array $data = [], bool $withCsrf = true, string $ip = '10.0.0.1'): Response
    {
        if ($withCsrf && !isset($data['_csrf'])) {
            $data['_csrf'] = $this->csrfToken();
        }
        return $this->send(new Request('POST', $path, [], $data, $this->cookies, [], $this->server($ip)));
    }

    /** Jeton CSRF de la session courante, lu en base ; une page avec formulaire est chargée si besoin. */
    protected function csrfToken(): string
    {
        foreach (['', '/login', '/profile/password'] as $page) {
            if ($page !== '') {
                $this->get($page);
            }
            $data = $this->app->db()->value('SELECT data FROM sessions WHERE id = ?', [hash('sha256', $this->cookies['kb_session'] ?? '')]);
            $token = (string) (json_decode((string) $data, true)['_csrf'] ?? '');
            if ($token !== '') {
                return $token;
            }
        }
        return '';
    }

    protected function login(string $email, string $password, string $ip = '10.0.0.1'): Response
    {
        $this->get('/login', [], $ip);
        return $this->post('/login', ['email' => $email, 'password' => $password], true, $ip);
    }

    /** @param list<string> $roles */
    protected function createUser(string $email, array $roles = ['collaborateur'], string $password = 'Motdepasse123', array $extra = []): int
    {
        $db = $this->app->db();
        $id = $db->insert('users', [
            'email' => $email,
            'password_hash' => PasswordPolicy::hash($password),
            'first_name' => 'Test',
            'last_name' => ucfirst(strstr($email, '@', true) ?: 'user'),
            'status' => 'active',
            'created_at' => now_utc(),
            'updated_at' => now_utc(),
            ...$extra,
        ]);
        foreach ($roles as $role) {
            $db->run('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $role]);
        }
        return $id;
    }

    private function send(Request $request): Response
    {
        $response = $this->app->handle($request, $this->router);
        foreach ($response->cookies as $c) {
            if ($c['value'] === '' || ($c['options']['expires'] ?? 0) !== 0 && $c['options']['expires'] < time()) {
                unset($this->cookies[$c['name']]);
            } else {
                $this->cookies[$c['name']] = $c['value'];
            }
        }
        return $response;
    }

    /** @return array<string,string> */
    private function server(string $ip): array
    {
        return ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'PHPUnit'];
    }

    protected function assertRedirect(Response $r, string $to): void
    {
        $this->assertContains($r->status, [301, 302, 303], 'Statut attendu : redirection. Reçu ' . $r->status . "\n" . mb_substr(strip_tags($r->body), 0, 500));
        $this->assertSame($to, $r->headers['Location'] ?? null);
    }
}
