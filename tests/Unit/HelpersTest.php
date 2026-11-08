<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testEscapeNeutralisesHtmlAndAttributes(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&apos;', e('<script>alert("x")</script>\''));
    }

    public function testInitials(): void
    {
        $this->assertSame('ÉK', initials('élodie', 'koné'));
    }

    public function testDeviceLabel(): void
    {
        $this->assertSame('iPhone · Safari', device_label('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('Android · Chrome', device_label('Mozilla/5.0 (Linux; Android 14; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'));
        $this->assertSame('Windows · Edge', device_label('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0'));
        $this->assertSame('Linux · Firefox', device_label('Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'));
        $this->assertSame('Appareil inconnu', device_label(''));
    }

    public function testUserStatus(): void
    {
        $this->assertSame(['Désactivé', 'secondary'], user_status(['status' => 'disabled', 'last_login_at' => '2026-01-01 00:00:00']));
        $this->assertSame(['Pas encore connecté', 'warning'], user_status(['status' => 'active', 'last_login_at' => null]));
        $this->assertSame(['Actif', 'success'], user_status(['status' => 'active', 'last_login_at' => '2026-01-01 00:00:00']));
    }

    public function testAvatarFallsBackToInitials(): void
    {
        $this->assertStringContainsString('AD', avatar(['id' => 7, 'first_name' => 'Awa', 'last_name' => 'Diallo', 'avatar_path' => null]));
        $html = avatar(['id' => 7, 'first_name' => 'Awa', 'last_name' => 'Diallo', 'avatar_path' => 'avatars/7-abc.webp']);
        $this->assertStringContainsString('/directory/7/photo?v=7-abc', $html);
        $this->assertStringContainsString('<img', $html);
    }
}
