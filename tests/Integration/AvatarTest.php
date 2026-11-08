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

    /** JPEG 400×200 (moitié gauche rouge, droite bleue) avec EXIF Orientation = 6 (à tourner de 90° horaire). */
    private function rotatedJpeg(): string
    {
        $img = imagecreatetruecolor(400, 200);
        imagefilledrectangle($img, 0, 0, 199, 199, imagecolorallocate($img, 220, 0, 0));
        imagefilledrectangle($img, 200, 0, 399, 199, imagecolorallocate($img, 0, 0, 220));
        ob_start();
        imagejpeg($img, null, 95);
        $jpeg = (string) ob_get_clean();
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
        $payload = "Exif\0\0" . $tiff;
        $path = $this->tmp . '/rotated.jpg';
        file_put_contents($path, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2));
        return $path;
    }

    public function testExifOrientationIsApplied(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->rotatedJpeg())]);
        $img = imagecreatefromstring($this->get("/directory/$id/photo")->body);
        $top = imagecolorsforindex($img, imagecolorat($img, 128, 30));
        $bottom = imagecolorsforindex($img, imagecolorat($img, 128, 226));
        $this->assertGreaterThan(150, $top['red'], 'le haut doit être rouge (gauche de la photo brute)');
        $this->assertGreaterThan(150, $bottom['blue'], 'le bas doit être bleu');
    }

    public function testTooManyPixelsAreRejected(): void
    {
        $id = $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $img = imagecreatetruecolor(6000, 4100);
        imagepng($img, $this->tmp . '/big.png', 9);
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->tmp . '/big.png', 'big.png')]);
        $this->assertSame([], $this->storedFiles($id));
        $this->assertStringContainsString('24 mégapixels', $this->get('/profile')->body);
    }

    public function testUploadAboveServerLimitShowsSizeMessage(): void
    {
        $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $this->postFiles('/profile/photo', [], ['photo' => ['name' => 'p.jpg', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]]);
        $this->assertStringContainsString('ne doit pas dépasser 5 Mo', $this->get('/profile')->body);
    }

    public function testOversizedPostGivesClearMessageNotCsrfError(): void
    {
        $this->createUser('moi@kibarou.test');
        $this->login('moi@kibarou.test', 'Motdepasse123');
        $r = $this->app->handle(new \App\Core\Request('POST', '/profile/photo', [], [], $this->cookies, [],
            ['REMOTE_ADDR' => '10.0.0.1', 'CONTENT_LENGTH' => (string) (20 * 1024 * 1024)]), $this->router);
        $this->assertSame(413, $r->status);
        $this->assertStringContainsString('trop volumineux', $r->body);
    }

    public function testOwnPhotoVisibleWithoutDirectoryPermission(): void
    {
        $this->app->db()->insert('roles', ['slug' => 'prestataire', 'name' => 'Prestataire', 'is_system' => 0, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $id = $this->createUser('presta@kibarou.test', ['prestataire']);
        $this->login('presta@kibarou.test', 'Motdepasse123');
        $this->postFiles('/profile/photo', [], ['photo' => $this->file($this->jpegWithExif())]);
        $this->assertSame(200, $this->get("/directory/$id/photo")->status);
        $profile = $this->get('/profile')->body;
        $this->assertStringNotContainsString('Mon statut du jour', $profile);
    }
}
