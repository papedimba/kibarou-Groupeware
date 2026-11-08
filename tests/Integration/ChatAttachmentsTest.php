<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Response;

final class ChatAttachmentsTest extends IntegrationTestCase
{
    private function u(int $id): array
    {
        return $this->app->db()->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed> */
    private function json(Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    /** @return array<string,array<string,mixed>> */
    private function photo(): array
    {
        $img = imagecreatetruecolor(800, 600);
        $path = tempnam(sys_get_temp_dir(), 'kbp');
        imagejpeg($img, $path);
        return ['file' => ['name' => 'chantier.jpg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)]];
    }

    /** @return array<string,array<string,mixed>> */
    private function docx(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kbd');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types><Override ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();
        return ['file' => ['name' => 'Compte rendu é.docx', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)]];
    }

    private function upload(int $cid, array $files): int
    {
        $r = $this->postFiles("/api/chat/conversations/$cid/attachments", [], $files);
        $this->assertSame(200, $r->status, $r->body);
        return (int) $this->json($r)['attachment']['id'];
    }

    private function relogin(string $email): void
    {
        $this->cookies = [];
        $this->login($email, 'Motdepasse123');
    }

    public function testUploadThenSendAttachesAndPayloadListsFiles(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $att = $this->upload($cid, $this->photo());
        $r = $this->post("/api/chat/conversations/$cid/messages", ['body' => '', 'attachment_ids' => [(string) $att]]);
        $this->assertSame(200, $r->status, $r->body);
        $this->relogin('b@kibarou.test');
        $ev = $this->json($this->get('/api/chat/updates', ['since' => '0']))['events'][0];
        $this->assertSame('image', $ev['attachments'][0]['kind']);
        $this->assertSame("/chat/attachments/$att/thumb", $ev['attachments'][0]['thumb_url']);
        $thumb = $this->get("/chat/attachments/$att/thumb");
        $this->assertSame(200, $thumb->status);
        $this->assertSame('image/webp', $thumb->headers['Content-Type']);
    }

    public function testForeignAttachmentRejected(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $ab = $this->app->chat()->directWith($this->u($a), $b);
        $ac = $this->app->chat()->directWith($this->u($a), $c);
        $this->login('b@kibarou.test', 'Motdepasse123');
        $ofB = $this->upload($ab, $this->photo());
        $this->relogin('a@kibarou.test');
        $otherConversation = $this->upload($ac, $this->photo());
        $used = $this->upload($ab, $this->photo());
        $this->assertSame(200, $this->post("/api/chat/conversations/$ab/messages", ['body' => 'ok', 'attachment_ids' => [(string) $used]])->status);
        $before = (int) $this->app->db()->value('SELECT COUNT(*) FROM messages');
        foreach ([$ofB, $otherConversation, $used] as $id) {
            $r = $this->post("/api/chat/conversations/$ab/messages", ['body' => 'vol', 'attachment_ids' => [(string) $id]]);
            $this->assertSame(422, $r->status);
            $this->assertSame('Une pièce jointe est introuvable ou déjà envoyée.', $this->json($r)['errors']['attachments']);
        }
        $this->assertSame($before, (int) $this->app->db()->value('SELECT COUNT(*) FROM messages'));
    }

    public function testDownloadRequiresVisibleMessage(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $this->createUser('d@kibarou.test');
        $e = $this->createUser('e@kibarou.test');
        $g = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $photo = $this->upload($g, $this->photo());
        $doc = $this->upload($g, $this->docx());
        $this->post("/api/chat/conversations/$g/messages", ['body' => 'pièces', 'attachment_ids' => [(string) $photo, (string) $doc]]);
        $file = $this->get("/chat/attachments/$doc");
        $this->assertSame(200, $file->status);
        $this->assertStringStartsWith('attachment; filename=', $file->headers['Content-Disposition']);
        $this->assertStringContainsString("filename*=UTF-8''Compte%20rendu%20%C3%A9.docx", $file->headers['Content-Disposition']);
        $this->assertSame('nosniff', $file->headers['X-Content-Type-Options']);
        $this->app->chat()->leave($this->u($c), $g);
        $this->app->chat()->addMembers($this->u($a), $g, [$e]);
        foreach (['c@kibarou.test', 'd@kibarou.test', 'e@kibarou.test'] as $who) {
            $this->relogin($who);
            foreach (["/chat/attachments/$photo", "/chat/attachments/$photo/thumb", "/chat/attachments/$doc"] as $url) {
                $this->assertSame(404, $this->get($url)->status, "$who $url");
            }
        }
    }

    public function testSameClientIdCreatesOneMessage(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $this->login('a@kibarou.test', 'Motdepasse123');
        $client = str_repeat('ab', 16);
        $first = $this->json($this->post("/api/chat/conversations/$cid/messages", ['body' => 'une fois', 'client_id' => $client]));
        $second = $this->json($this->post("/api/chat/conversations/$cid/messages", ['body' => 'une fois', 'client_id' => $client]));
        $this->assertSame($first['event']['id'], $second['event']['id']);
        $this->assertSame($client, $first['event']['client_id']);
        $this->assertSame(1, (int) $this->app->db()->value('SELECT COUNT(*) FROM messages'));
        $this->assertSame(1, (int) $this->app->db()->value("SELECT COUNT(*) FROM notifications WHERE type = 'chat.message'"));
        $this->assertSame(422, $this->post("/api/chat/conversations/$cid/messages", ['body' => 'x', 'client_id' => 'pas-hexa'])->status);
    }

    public function testReplyExcerptAndHiddenForLateMember(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $c = $this->createUser('c@kibarou.test');
        $d = $this->createUser('d@kibarou.test');
        $g = $this->app->chat()->createGroup($this->u($a), 'Groupe', [$b, $c]);
        $orig = $this->app->chat()->send($this->u($a), $g, 'Coulage demain à 7 h, prévoir la pompe');
        $this->app->chat()->addMembers($this->u($a), $g, [$d]);
        $this->app->chat()->send($this->u($b), $g, 'Bien reçu', null, [], $orig['message_id']);
        $this->login('c@kibarou.test', 'Motdepasse123');
        $events = $this->json($this->get('/api/chat/updates', ['since' => '0']))['events'];
        $reply = end($events)['reply_to'];
        $this->assertSame('Coulage demain à 7 h, prévoir la pompe', $reply['excerpt']);
        $this->assertSame('Test A', $reply['sender_name']);
        $this->relogin('d@kibarou.test');
        $events = $this->json($this->get('/api/chat/updates', ['since' => '0']))['events'];
        $reply = end($events)['reply_to'];
        $this->assertTrue($reply['hidden']);
        $this->assertArrayNotHasKey('excerpt', $reply);
        $this->assertStringNotContainsString('pompe', json_encode($events, JSON_UNESCAPED_UNICODE));
    }

    public function testUploadRateLimit(): void
    {
        $a = $this->createUser('a@kibarou.test');
        $b = $this->createUser('b@kibarou.test');
        $cid = $this->app->chat()->directWith($this->u($a), $b);
        $db = $this->app->db();
        for ($i = 0; $i < 30; $i++) {
            $db->insert('chat_attachments', ['conversation_id' => $cid, 'uploader_id' => $a, 'kind' => 'file', 'original_name' => 'x.pdf',
                'stored_path' => 'chat/2026/11/' . bin2hex(random_bytes(16)) . '.bin', 'mime' => 'application/pdf', 'size' => 1, 'created_at' => now_utc()]);
        }
        $this->login('a@kibarou.test', 'Motdepasse123');
        $this->assertSame(429, $this->postFiles("/api/chat/conversations/$cid/attachments", [], $this->photo())->status);
    }
}
