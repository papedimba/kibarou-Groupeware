<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\ValidationException;
use App\Modules\Chat\ChatAttachmentService;

final class ChatAttachmentServiceTest extends IntegrationTestCase
{
    private string $dir;
    private string $tmp;
    private ChatAttachmentService $service;
    private int $cid;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/kb-chat-' . bin2hex(random_bytes(4));
        $this->tmp = $this->dir . '-in';
        mkdir($this->tmp, 0777, true);
        $this->service = new ChatAttachmentService($this->app->db(), $this->dir);
        $this->uid = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $this->cid = $this->app->chat()->directWith($this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$this->uid]), $b);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir) . ' ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    /** @return array<string,mixed> entrée au format $_FILES */
    private function file(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $path = $this->tmp . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => $error, 'size' => strlen($content)];
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 200, 80, 20));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        // Segment APP1 « Exif » minimal inséré après SOI : il ne doit pas survivre au réencodage.
        $exif = "Exif\0\0" . 'II*' . "\0" . pack('V', 8) . pack('v', 0) . 'GPS-SECRET';
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = $this->tmp . '/z' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $n => $c) {
            $zip->addFromString($n, $c);
        }
        $zip->close();
        return (string) file_get_contents($path);
    }

    private function docx(bool $macros = false): string
    {
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            'word/document.xml' => '<w:document/>',
        ];
        if ($macros) {
            $entries['word/vbaProject.bin'] = 'VBA';
        }
        return $this->zip($entries);
    }

    private function rejected(array $file): string
    {
        try {
            $this->service->store($this->uid, $this->cid, $file);
        } catch (ValidationException $e) {
            return $e->errors['file'];
        }
        $this->fail('Fichier accepté à tort : ' . $file['name']);
    }

    public function testPhotoIsReencodedResizedAndStripped(): void
    {
        $row = $this->service->store($this->uid, $this->cid, $this->file('chantier.jpg', $this->jpeg(3000, 1500)));
        $this->assertSame('image', $row['kind']);
        $this->assertSame('image/webp', $row['mime']);
        $this->assertSame(2000, (int) $row['width']);
        $this->assertSame(1000, (int) $row['height']);
        $this->assertNull($row['message_id']);
        $stored = (string) file_get_contents((string) $this->service->absolutePath($row['stored_path']));
        $this->assertStringNotContainsString('GPS-SECRET', $stored);
        $thumb = getimagesize((string) $this->service->absolutePath($row['thumb_path']));
        $this->assertSame(480, $thumb[0]);
    }

    public function testDocxAndXlsxAndPdfAccepted(): void
    {
        $docx = $this->docx();
        $row = $this->service->store($this->uid, $this->cid, $this->file('Devis "lot3"<v2>.docx', $docx));
        $this->assertSame('file', $row['kind']);
        $this->assertSame('Devis lot3v2.docx', $row['original_name']);
        $this->assertSame($docx, file_get_contents((string) $this->service->absolutePath($row['stored_path'])));
        $xlsx = $this->zip([
            '[Content_Types].xml' => '<Types><Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>',
            'xl/workbook.xml' => '<workbook/>',
        ]);
        $this->assertSame('file', $this->service->store($this->uid, $this->cid, $this->file('Suivi.xlsx', $xlsx))['kind']);
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $this->assertSame('file', $this->service->store($this->uid, $this->cid, $this->file('plan.pdf', $pdf))['kind']);
    }

    public function testRejectsFakesWithExactMessages(): void
    {
        $type = 'Ce type de fichier n\'est pas accepté. Formats possibles : photos, Word (.docx), Excel (.xlsx), PDF.';
        $this->assertSame($type, $this->rejected($this->file('photo.jpg', '<?php system($_GET["c"]);')));
        $this->assertSame($type, $this->rejected($this->file('rapport.docx', $this->zip(['script.sh' => 'rm -rf /']))));
        $this->assertSame($type, $this->rejected($this->file('rapport.docx', $this->docx(true))));
        $this->assertSame($type, $this->rejected($this->file('rapport.docm', $this->docx())));
        $this->assertSame($type, $this->rejected($this->file('plan.pdf', $this->docx())));
        $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504);
        $this->assertSame('Les anciens formats .doc et .xls ne sont pas acceptés. Enregistrez le fichier au format .docx ou .xlsx avant de l\'envoyer.', $this->rejected($this->file('vieux.doc', $ole)));
        $this->assertSame(0, (int) $this->app->db()->value('SELECT COUNT(*) FROM chat_attachments'));
    }

    public function testTooHeavyPhotoMessage(): void
    {
        $this->assertSame(
            'Cette photo est trop lourde (6 Mo au maximum). Prenez-la en qualité réduite ou envoyez-la depuis un autre appareil.',
            $this->rejected(['name' => 'grosse.jpg', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]),
        );
    }

    public function testPurgeOrphansDeletesOldUnattachedOnly(): void
    {
        $old = $this->service->store($this->uid, $this->cid, $this->file('a.jpg', $this->jpeg(100, 100)));
        $recent = $this->service->store($this->uid, $this->cid, $this->file('b.jpg', $this->jpeg(100, 100)));
        $attached = $this->service->store($this->uid, $this->cid, $this->file('c.jpg', $this->jpeg(100, 100)));
        $db = $this->app->db();
        $mid = $db->insert('messages', ['conversation_id' => $this->cid, 'sender_id' => $this->uid, 'body' => '', 'created_at' => now_utc()]);
        $db->run('UPDATE chat_attachments SET created_at = UTC_TIMESTAMP() - INTERVAL 25 HOUR WHERE id IN (?, ?)', [$old['id'], $attached['id']]);
        $db->update('chat_attachments', ['message_id' => $mid], ['id' => $attached['id']]);
        $oldPath = (string) $this->service->absolutePath($old['stored_path']);
        $this->assertSame(1, $this->service->purgeOrphans());
        $this->assertFileDoesNotExist($oldPath);
        $this->assertNull($db->fetch('SELECT id FROM chat_attachments WHERE id = ?', [$old['id']]));
        $this->assertNotNull($this->service->absolutePath($recent['stored_path']));
        $this->assertNotNull($this->service->absolutePath($attached['thumb_path']));
    }

    public function testAbsolutePathRejectsTraversal(): void
    {
        $this->assertNull($this->service->absolutePath('../../.env'));
        $this->assertNull($this->service->absolutePath('avatars/1-0123456789abcdef.webp'));
        $this->assertNull($this->service->absolutePath('chat/2026/11/' . str_repeat('a', 32) . '.php'));
    }
}
