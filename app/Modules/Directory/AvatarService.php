<?php

declare(strict_types=1);

namespace App\Modules\Directory;

use App\Core\Database;
use App\Core\ValidationException;

/**
 * Photos de profil : contrôle, recadrage carré 256×256 et réencodage WebP par GD.
 * Le réencodage supprime les métadonnées (EXIF, GPS) et tout contenu qui n'est pas une image.
 * Les fichiers vivent dans storage/private/avatars, jamais sous la racine web.
 */
final class AvatarService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_SIDE = 6000;
    // Décoder 6000×6000 coûte ~280 Mo de mémoire : plafond en pixels pour rester sûr sur un mutualisé.
    private const MAX_PIXELS = 24_000_000;
    private const SIZE = 256;
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly Database $db, private readonly string $privateDir)
    {
    }

    /** @param array<string,mixed> $file entrée de $_FILES */
    public function store(int $userId, array $file): void
    {
        $image = $this->decode($file);
        $side = min(imagesx($image), imagesy($image));
        $square = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopyresampled(
            $square,
            $image,
            0,
            0,
            intdiv(imagesx($image) - $side, 2),
            intdiv(imagesy($image) - $side, 2),
            self::SIZE,
            self::SIZE,
            $side,
            $side,
        );
        $dir = $this->privateDir . '/avatars';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier des photos impossible à créer.');
        }
        $relative = 'avatars/' . $userId . '-' . bin2hex(random_bytes(8)) . '.webp';
        if (!imagewebp($square, $this->privateDir . '/' . $relative, 82)) {
            throw new \RuntimeException('Écriture de la photo impossible.');
        }
        $old = $this->db->value('SELECT avatar_path FROM users WHERE id = ?', [$userId]);
        $this->db->update('users', ['avatar_path' => $relative, 'updated_at' => now_utc()], ['id' => $userId]);
        $this->unlink($old !== null ? (string) $old : null);
    }

    public function remove(int $userId): void
    {
        $old = $this->db->value('SELECT avatar_path FROM users WHERE id = ?', [$userId]);
        $this->db->update('users', ['avatar_path' => null, 'updated_at' => now_utc()], ['id' => $userId]);
        $this->unlink($old !== null ? (string) $old : null);
    }

    /** Chemin absolu d'une photo ; refuse tout chemin hors du dossier des photos. */
    public function absolutePath(string $relative): ?string
    {
        if (!preg_match('#^avatars/\d+-[a-f0-9]{16}\.webp$#', $relative)) {
            return null;
        }
        $path = $this->privateDir . '/' . $relative;
        return is_file($path) ? $path : null;
    }

    /** @param array<string,mixed> $file */
    private function decode(array $file): \GdImage
    {
        $fail = static fn (string $m) => new ValidationException(['photo' => $m]);
        if (!function_exists('imagewebp')) {
            throw $fail('Le serveur ne peut pas traiter les images. Contactez un administrateur.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw $fail('La photo ne doit pas dépasser 5 Mo.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            throw $fail('Choisissez une photo à envoyer.');
        }
        // La taille déclarée par le navigateur n'est pas fiable : on mesure le fichier.
        if ((int) filesize($tmp) > self::MAX_BYTES) {
            throw $fail('La photo ne doit pas dépasser 5 Mo.');
        }
        if (!in_array((new \finfo(FILEINFO_MIME_TYPE))->file($tmp), self::TYPES, true)) {
            throw $fail('Seules les images JPEG, PNG ou WebP sont acceptées.');
        }
        $size = @getimagesize($tmp);
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw $fail('Cette image est illisible ou trop grande (6000 × 6000 pixels au maximum).');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw $fail('Cette image est trop grande (24 mégapixels au maximum).');
        }
        $image = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($image === false) {
            throw $fail('Cette image est illisible.');
        }
        return $this->orient($image, $tmp, (int) $size[2]);
    }

    /** Les téléphones enregistrent souvent la photo couchée + une balise EXIF d'orientation, perdue au réencodage. */
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

    private function unlink(?string $relative): void
    {
        $path = $relative !== null ? $this->absolutePath($relative) : null;
        if ($path !== null) {
            @unlink($path);
        }
    }
}
