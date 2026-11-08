<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Core\Database;
use App\Core\ValidationException;

/**
 * Pièces jointes de la messagerie : contrôle du contenu réel, réencodage des photos, stockage privé.
 * Photos : WebP, grand côté ≤ 2 000 px, vignette 480 px, métadonnées supprimées (seule la version réduite est gardée).
 * Fichiers : .docx, .xlsx (vrais documents Office sans macros) et PDF, conservés tels quels, jamais servis en ligne.
 */
final class ChatAttachmentService
{
    public const MAX_PER_MESSAGE = 10;
    private const MAX_BYTES = 6 * 1024 * 1024;
    private const MAX_PIXELS = 24_000_000;
    private const SIDE = 2000;
    private const THUMB = 480;
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MSG_TYPE = 'Ce type de fichier n\'est pas accepté. Formats possibles : photos, Word (.docx), Excel (.xlsx), PDF.';
    private const MSG_OLD = 'Les anciens formats .doc et .xls ne sont pas acceptés. Enregistrez le fichier au format .docx ou .xlsx avant de l\'envoyer.';
    private const MSG_HEAVY = 'Cette photo est trop lourde (6 Mo au maximum). Prenez-la en qualité réduite ou envoyez-la depuis un autre appareil.';
    /** Extension → [types finfo acceptés, marqueur attendu dans [Content_Types].xml]. */
    private const OFFICE = [
        'docx' => ['wordprocessingml.document.main+xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['spreadsheetml.sheet.main+xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];

    public function __construct(private readonly Database $db, private readonly string $privateDir)
    {
    }

    /**
     * @param array<string,mixed> $file entrée de $_FILES
     * @return array<string,mixed> ligne chat_attachments (non rattachée)
     */
    public function store(int $uploaderId, int $conversationId, array $file): array
    {
        $name = $this->cleanName((string) ($file['name'] ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isPhoto = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw $this->fail($isPhoto ? self::MSG_HEAVY : 'Ce fichier est trop lourd (6 Mo au maximum).');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            throw $this->fail('Choisissez un fichier à envoyer.');
        }
        if ((int) filesize($tmp) > self::MAX_BYTES) {
            throw $this->fail($isPhoto ? self::MSG_HEAVY : 'Ce fichier est trop lourd (6 Mo au maximum).');
        }
        if (in_array($ext, ['doc', 'xls'], true)) {
            throw $this->fail(self::MSG_OLD);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $base = 'chat/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(16));
        $row = ['conversation_id' => $conversationId, 'uploader_id' => $uploaderId, 'message_id' => null,
            'original_name' => $name, 'created_at' => now_utc()];

        if ($isPhoto && in_array($mime, self::IMAGE_TYPES, true)) {
            [$image, $thumb] = $this->images($tmp);
            $this->write($base . '.webp', fn (string $p) => imagewebp($image, $p, 82));
            $this->write($base . '-t.webp', fn (string $p) => imagewebp($thumb, $p, 78));
            $row += ['kind' => 'image', 'stored_path' => $base . '.webp', 'thumb_path' => $base . '-t.webp', 'mime' => 'image/webp',
                'size' => (int) filesize($this->privateDir . '/' . $base . '.webp'), 'width' => imagesx($image), 'height' => imagesy($image)];
        } elseif ($this->isDocument($ext, $mime, $tmp)) {
            $this->write($base . '.bin', fn (string $p) => copy($tmp, $p));
            $row += ['kind' => 'file', 'stored_path' => $base . '.bin', 'thumb_path' => null,
                'mime' => $ext === 'pdf' ? 'application/pdf' : self::OFFICE[$ext][1], 'size' => (int) filesize($tmp), 'width' => null, 'height' => null];
        } else {
            throw $this->fail(self::MSG_TYPE);
        }
        $row['id'] = $this->db->insert('chat_attachments', $row);
        return $row;
    }

    /** Chemin absolu d'un fichier stocké ; refuse tout chemin hors du dossier de la messagerie. */
    public function absolutePath(?string $relative): ?string
    {
        if ($relative === null || !preg_match('#^chat/\d{4}/\d{2}/[a-f0-9]{32}(-t)?\.(webp|bin)$#', $relative)) {
            return null;
        }
        $path = $this->privateDir . '/' . $relative;
        return is_file($path) ? $path : null;
    }

    /** @param list<array<string,mixed>> $rows */
    public function deleteFiles(array $rows): void
    {
        foreach ($rows as $row) {
            foreach ([$row['stored_path'] ?? null, $row['thumb_path'] ?? null] as $relative) {
                $path = $this->absolutePath($relative);
                if ($path !== null) {
                    @unlink($path);
                }
            }
        }
    }

    /** Pièces envoyées mais jamais rattachées à un message (onglet fermé, envoi abandonné) depuis plus de 24 h. */
    public function purgeOrphans(): int
    {
        // Verrou partagé avec l'envoi (ChatService::send, FOR UPDATE) : une pièce rattachée entre-temps n'est jamais effacée.
        $rows = $this->db->transaction(function () {
            $rows = $this->db->fetchAll('SELECT * FROM chat_attachments WHERE message_id IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 24 HOUR FOR UPDATE');
            foreach ($rows as $row) {
                $this->db->run('DELETE FROM chat_attachments WHERE id = ? AND message_id IS NULL', [$row['id']]);
            }
            return $rows;
        });
        $this->deleteFiles($rows);
        return count($rows);
    }

    /** @return array{0:\GdImage,1:\GdImage} photo réduite et vignette */
    private function images(string $tmp): array
    {
        if (!function_exists('imagewebp')) {
            throw $this->fail('Le serveur ne peut pas traiter les images. Contactez un administrateur.');
        }
        $size = @getimagesize($tmp);
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw $this->fail('Cette image est illisible.');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw $this->fail(self::MSG_HEAVY);
        }
        $image = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($image === false) {
            throw $this->fail('Cette image est illisible.');
        }
        $image = $this->orient($image, $tmp, (int) $size[2]);
        return [$this->fit($image, self::SIDE), $this->fit($image, self::THUMB)];
    }

    private function fit(\GdImage $image, int $max): \GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $ratio = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $out = imagecreatetruecolor($nw, $nh);
        // Fond blanc : un PNG transparent ne devient pas noir.
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }

    /** Les téléphones enregistrent souvent la photo couchée avec une balise d'orientation, perdue au réencodage. */
    private function orient(\GdImage $image, string $tmp, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $image;
        }
        $angle = match ((int) ((@exif_read_data($tmp) ?: [])['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    /** .docx / .xlsx : vrai paquet Office du bon type, sans macros ; PDF : signature PDF. */
    private function isDocument(string $ext, string $mime, string $tmp): bool
    {
        if ($ext === 'pdf') {
            return $mime === 'application/pdf';
        }
        if (!isset(self::OFFICE[$ext]) || !in_array($mime, ['application/zip', self::OFFICE[$ext][1]], true)) {
            return false;
        }
        // Sans l'extension zip, impossible de vérifier : refus plutôt qu'acceptation aveugle.
        if (!class_exists(\ZipArchive::class)) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        // Lecture bornée à 64 Ko : une « bombe » de décompression ne peut pas saturer la mémoire.
        $types = (string) $zip->getFromName('[Content_Types].xml', 65536);
        $macros = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), 'vbaproject.bin')) {
                $macros = true;
            }
        }
        $zip->close();
        return !$macros && str_contains($types, self::OFFICE[$ext][0]);
    }

    private function write(string $relative, callable $writer): void
    {
        $path = $this->privateDir . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier des pièces jointes impossible à créer.');
        }
        if (!$writer($path)) {
            throw new \RuntimeException('Écriture de la pièce jointe impossible.');
        }
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/u', '', basename(str_replace('\\', '/', $name))) ?? '';
        $name = trim($name);
        return mb_substr($name !== '' ? $name : 'fichier', 0, 160);
    }

    private function fail(string $message): ValidationException
    {
        return new ValidationException(['file' => $message]);
    }
}
