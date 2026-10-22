<?php

declare(strict_types=1);

namespace Modulento\Dating;

use Modulento\Core\Support\Clock;
use PDO;

/**
 * A profile's photo gallery, same shape as the core's own offer_image and
 * modulento-ext-card's Gallery: never stored as uploaded, decoded and
 * re-encoded fresh (strips EXIF, including GPS, and rejects anything that
 * is not really a picture). Files live outside the web root and are served
 * by PhotoController. The first photo uploaded becomes the primary one
 * automatically; swapping it is an explicit action.
 */
final class Photos
{
    public const MAX_PHOTOS = 6;
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;
    private const LARGE_EDGE = 1600;
    private const THUMB_EDGE = 480;
    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor');
    }

    /** @return list<array> ordered by position, then id */
    public function ofProfile(int $profileId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_dating_photo WHERE profile_id = :id ORDER BY position, id');
        $stmt->execute(['id' => $profileId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array{tmp_name?: string, error?: int} $upload one entry of $_FILES
     * @return string|null language key of the problem, null on success
     */
    public function add(int $profileId, array $upload): ?string
    {
        if (!self::available()) {
            return 'dating.photo.error.unavailable';
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'dating.photo.error.too_large'
                : 'dating.photo.error.upload';
        }
        if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
            return 'dating.photo.error.too_large';
        }
        $current = $this->ofProfile($profileId);
        if (count($current) >= self::MAX_PHOTOS) {
            return 'dating.photo.error.too_many';
        }

        $info = @getimagesize($upload['tmp_name']);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            return 'dating.photo.error.type';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return 'dating.photo.error.dimensions';
        }

        $source = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if ($source === false) {
            return 'dating.photo.error.type';
        }

        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $name = bin2hex(random_bytes(16));
        $dir = $this->uploadDir . '/' . $profileId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'dating.photo.error.storage';
        }

        $large = $this->resized($source, self::LARGE_EDGE);
        $thumb = $this->resized($source, self::THUMB_EDGE);
        $written = $this->write($large, "{$dir}/{$name}.{$extension}", $extension)
            && $this->write($thumb, "{$dir}/{$name}_thumb.{$extension}", $extension);
        imagedestroy($source);
        imagedestroy($large);
        imagedestroy($thumb);

        if (!$written) {
            @unlink("{$dir}/{$name}.{$extension}");
            @unlink("{$dir}/{$name}_thumb.{$extension}");

            return 'dating.photo.error.storage';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO x_dating_photo (profile_id, name, extension, is_primary, position, created_at)
             VALUES (:profile, :name, :extension, :primary, :position, :now)'
        );
        $stmt->execute([
            'profile' => $profileId, 'name' => $name, 'extension' => $extension,
            'primary' => $current === [] ? 1 : 0, 'position' => count($current), 'now' => Clock::now(),
        ]);

        return null;
    }

    public function primary(int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM x_dating_photo WHERE profile_id = :id ORDER BY is_primary DESC, position, id LIMIT 1"
        );
        $stmt->execute(['id' => $profileId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function delete(int $profileId, int $photoId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM x_dating_photo WHERE id = :id AND profile_id = :profile');
        $stmt->execute(['id' => $photoId, 'profile' => $profileId]);
        $photo = $stmt->fetch();
        if (!$photo) {
            return;
        }

        $this->db->prepare('DELETE FROM x_dating_photo WHERE id = :id')->execute(['id' => $photoId]);
        $this->unlinkFiles($profileId, $photo);

        if ((int) $photo['is_primary'] === 1) {
            $next = $this->db->prepare('SELECT id FROM x_dating_photo WHERE profile_id = :profile ORDER BY position, id LIMIT 1');
            $next->execute(['profile' => $profileId]);
            $nextId = $next->fetchColumn();
            if ($nextId !== false) {
                $this->makePrimary($profileId, (int) $nextId);
            }
        }
    }

    public function makePrimary(int $profileId, int $photoId): void
    {
        $this->db->prepare('UPDATE x_dating_photo SET is_primary = 0 WHERE profile_id = :profile')->execute(['profile' => $profileId]);
        $this->db->prepare('UPDATE x_dating_photo SET is_primary = 1 WHERE id = :id AND profile_id = :profile')
            ->execute(['id' => $photoId, 'profile' => $profileId]);
    }

    /** The stored file for a request, or null - only names this class created can match. */
    public function path(int $profileId, string $file): ?string
    {
        if (preg_match('/^[0-9a-f]{32}(_thumb)?\.(webp|jpg)$/', $file) !== 1) {
            return null;
        }

        $path = $this->uploadDir . '/' . $profileId . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** Paths (below /media) of a photo row, for templates. */
    public static function urls(array $photo): array
    {
        $base = '/media/dating/' . $photo['profile_id'] . '/' . $photo['name'];

        return [
            'id' => (int) $photo['id'],
            'large' => $base . '.' . $photo['extension'],
            'thumb' => $base . '_thumb.' . $photo['extension'],
            'is_primary' => (bool) $photo['is_primary'],
        ];
    }

    private function unlinkFiles(int $profileId, array $photo): void
    {
        $base = $this->uploadDir . '/' . $profileId . '/' . $photo['name'];
        @unlink($base . '.' . $photo['extension']);
        @unlink($base . '_thumb.' . $photo['extension']);
    }

    private function resized(\GdImage $source, int $maxEdge): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    private function write(\GdImage $image, string $path, string $extension): bool
    {
        return $extension === 'webp' ? imagewebp($image, $path, 82) : imagejpeg($image, $path, 85);
    }
}
