<?php

declare(strict_types=1);

namespace Tests\Integration;

final class AvatarTest extends IntegrationTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/kb-avatar-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    /** JPEG produit par GD, avec un segment EXIF ajouté juste après l'en-tête. */
    private function jpegWithExif(int $w = 800, int $h = 600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 30, 90, 200));
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        $payload = "Exif\0\0" . "II*\0\x08\0\0\0" . "\0\0" . "\0\0\0\0";
        $path = $this->tmp . '/photo.jpg';
        file_put_contents($path, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2));
        return $path;
    }

    /** @return array<string,mixed> */
    private function file(string $path, string $name = 'photo.jpg'): array
    {
        return ['name' => $name, 'type' => 'image/jpeg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }

    private function avatarDir(): string
    {
        return $this->app->config('paths.storage') . '/private/avatars';
    }

    /** @return list<string> */
    private function storedFiles(int $userId): array
    {
        return glob($this->avatarDir() . "/$userId-*.webp") ?: [];
    }

    public function testValidJpegIsStoredAs256Webp(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->postFiles('/profile/photo', [], ['photo' => $this->file($this->jpegWithExif())]), '/profile');
        $r = $this->get("/directory/$id/photo");
        $this->assertSame(200, $r->status);
        $this->assertSame('image/webp', $r->headers['Content-Type']);
        $info = getimagesizefromstring($r->body);
        $this->assertSame([256, 256, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $this->assertStringNotContainsString('Exif', $r->body);
        $this->assertStringContainsString('private', $r->headers['Cache-Control']);
    }

    public function testFakeImageIsRejected(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        file_put_contents($this->tmp . '/photo.jpg', '<?php echo 1;');
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->tmp . '/photo.jpg')]);
        $this->assertSame([], $this->storedFiles($id));
        $this->assertNull($this->app->db()->value('SELECT avatar_path FROM users WHERE id = ?', [$id]));
        $this->assertStringContainsString('image', $this->get('/profile')->body);
    }

    public function testTooLargeIsRejected(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $path = $this->tmp . '/big.jpg';
        file_put_contents($path, file_get_contents($this->jpegWithExif()) . str_repeat("\0", 5 * 1024 * 1024));
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($path)]);
        $this->assertSame([], $this->storedFiles($id));
    }

    public function testHugeDimensionsAreRejected(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $img = imagecreatetruecolor(6001, 10);
        imagepng($img, $this->tmp . '/wide.png');
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->tmp . '/wide.png', 'wide.png')]);
        $this->assertSame([], $this->storedFiles($id));
    }

    public function testReplaceDeletesOldFile(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->jpegWithExif())]);
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->jpegWithExif(300, 900))]);
        $this->assertCount(1, $this->storedFiles($id));
    }

    public function testPhotoRequiresLogin(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->jpegWithExif())]);
        $this->cookies = [];
        $this->assertRedirect($this->get("/directory/$id/photo"), '/login');
    }

    public function testAdminCanRemovePhoto(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->jpegWithExif())]);
        $this->cookies = [];
        $this->createUser('rh@kibarou.test', ['admin']);
        $this->login('rh@kibarou.test', 'Motdepasse123');
        $this->assertRedirect($this->post("/admin/users/$id/photo/delete"), "/admin/users/$id");
        $this->assertNull($this->app->db()->value('SELECT avatar_path FROM users WHERE id = ?', [$id]));
        $this->assertSame([], $this->storedFiles($id));
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.photo_removed' AND entity_id = ?", [$id]));
    }
}
