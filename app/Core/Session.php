<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session stockée en base (table `sessions`) : permet de lister et révoquer les sessions
 * d'un utilisateur. Le cookie contient un jeton aléatoire ; seule son empreinte SHA-256
 * est stockée, une fuite de la table ne permet donc pas de voler une session.
 */
final class Session
{
    private ?string $token = null;
    private ?string $id = null;
    /** @var array<string,mixed> */
    private array $data = [];
    private ?int $userId = null;
    private bool $dirty = false;
    private bool $exists = false;
    private ?string $lastActivity = null;
    private bool $destroyed = false;
    private string $ip = '';
    private string $userAgent = '';
    /** Appel d'arrière-plan (interrogation) : ne prolonge pas la session, ne consomme pas les messages flash. */
    private bool $passive = false;

    /** @param array{cookie:string,secure:bool,idle_minutes:int,absolute_hours:int} $config */
    public function __construct(private readonly Database $db, private readonly array $config)
    {
    }

    public function start(Request $request, bool $passive = false): void
    {
        $this->passive = $passive;
        $this->ip = $request->ip();
        $this->userAgent = $request->userAgent();
        $token = $request->cookies[$this->config['cookie']] ?? '';
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
            $row = $this->db->fetch(
                'SELECT * FROM sessions WHERE id = ? AND last_activity_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE
                 AND created_at > UTC_TIMESTAMP() - INTERVAL ? HOUR',
                [hash('sha256', $token), $this->config['idle_minutes'], $this->config['absolute_hours']],
            );
            if ($row !== null) {
                $this->token = $token;
                $this->id = (string) $row['id'];
                $this->data = json_decode((string) $row['data'], true) ?: [];
                $this->userId = $row['user_id'] !== null ? (int) $row['user_id'] : null;
                $this->lastActivity = (string) $row['last_activity_at'];
                $this->exists = true;
            }
        }
        if ($passive) {
            return;
        }
        // Les messages flash ne survivent qu'à une requête.
        $this->data['_flash_old'] = $this->data['_flash'] ?? [];
        unset($this->data['_flash']);
        if ($this->data['_flash_old'] !== []) {
            $this->dirty = true;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->data['_flash'][$key] = $value;
        $this->dirty = true;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->data['_flash_old'][$key] ?? $default;
    }

    /**
     * Garde une donnée sensible chiffrée en session, avec une durée de vie. La clé dérive du jeton
     * du cookie, que la base ne stocke pas : une fuite de la table sessions ne permet pas de la lire.
     * @param array<string,mixed> $value
     */
    public function seal(string $key, array $value, int $ttlSeconds): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $this->set($key, [
            'nonce' => base64_encode($nonce),
            'box' => base64_encode(sodium_crypto_secretbox(json_encode($value, JSON_THROW_ON_ERROR), $nonce, $this->sealKey())),
            'expires' => time() + $ttlSeconds,
        ]);
    }

    /** @return array<string,mixed>|null null si absente, expirée ou illisible (elle est alors effacée) */
    public function unseal(string $key): ?array
    {
        $item = $this->data[$key] ?? null;
        if (!is_array($item) || (int) ($item['expires'] ?? 0) < time() || $this->token === null) {
            if ($item !== null) {
                $this->forget($key);
            }
            return null;
        }
        $plain = sodium_crypto_secretbox_open((string) base64_decode((string) $item['box']), (string) base64_decode((string) $item['nonce']), $this->sealKey());
        $value = $plain === false ? null : json_decode($plain, true);
        return is_array($value) ? $value : null;
    }

    private function sealKey(): string
    {
        if ($this->token === null) {
            throw new \LogicException('Session sans jeton : impossible de chiffrer.');
        }
        return hash_hmac('sha256', 'kb-session-seal', $this->token, true);
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /** Nouvelle identité de session (anti-fixation) puis rattachement à l'utilisateur. */
    public function login(int $userId): void
    {
        $this->regenerate();
        $this->userId = $userId;
        $this->dirty = true;
    }

    public function regenerate(): void
    {
        if ($this->exists && $this->id !== null) {
            $this->db->run('DELETE FROM sessions WHERE id = ?', [$this->id]);
        }
        $this->token = null;
        $this->id = null;
        $this->exists = false;
        unset($this->data['_csrf']);
        $this->dirty = true;
    }

    public function destroy(): void
    {
        if ($this->id !== null) {
            $this->db->run('DELETE FROM sessions WHERE id = ?', [$this->id]);
        }
        $this->data = [];
        $this->userId = null;
        $this->destroyed = true;
    }

    public function csrfToken(): string
    {
        if (!isset($this->data['_csrf'])) {
            $this->set('_csrf', bin2hex(random_bytes(32)));
        }
        return (string) $this->data['_csrf'];
    }

    public function verifyCsrf(string $token): bool
    {
        return isset($this->data['_csrf']) && $token !== '' && hash_equals((string) $this->data['_csrf'], $token);
    }

    /** Persiste la session et pose le cookie. Écrit au plus une fois par minute si rien n'a changé. */
    public function save(Response $response): void
    {
        $cookie = $this->config['cookie'];
        if ($this->destroyed) {
            $response->withCookie($cookie, '', $this->cookieOptions(time() - 3600));
            return;
        }
        if ((!$this->exists && !$this->dirty) || ($this->passive && !$this->dirty)) {
            return;
        }
        $payload = $this->data;
        unset($payload['_flash_old']);
        if ($this->token === null) {
            $this->token = bin2hex(random_bytes(32));
            $this->id = hash('sha256', $this->token);
        }
        if (!$this->exists) {
            $this->db->insert('sessions', [
                'id' => $this->id,
                'user_id' => $this->userId,
                'ip_address' => $this->ip,
                'user_agent' => $this->userAgent,
                'data' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now_utc(),
                'last_activity_at' => now_utc(),
            ]);
            $this->exists = true;
        } elseif ($this->dirty || $this->lastActivity === null || strtotime($this->lastActivity . ' UTC') < time() - 60) {
            $this->db->update('sessions', [
                'user_id' => $this->userId,
                'ip_address' => $this->ip,
                'data' => json_encode($payload, JSON_THROW_ON_ERROR),
                'last_activity_at' => now_utc(),
            ], ['id' => $this->id]);
        }
        // Cookie de session navigateur (pas d'expiration) : l'expiration réelle est contrôlée en base.
        $response->withCookie($cookie, $this->token, $this->cookieOptions(0));
    }

    /** @return array<string,mixed> */
    private function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->config['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
