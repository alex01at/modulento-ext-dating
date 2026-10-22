<?php

declare(strict_types=1);

namespace Modulento\Dating;

use Modulento\Core\Controller\Controller;

/**
 * Serves profile photos from outside the web root - same shape as the
 * core's own MediaController and modulento-ext-card's
 * GalleryMediaController. Names are random and never reused, so every
 * response is cached forever.
 */
final class PhotoMediaController extends Controller
{
    private const TYPES = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public function show(array $params): void
    {
        $photos = new Photos($this->app->db, $this->uploadDir());
        $file = $photos->path((int) $params['id'], $params['file']);

        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';

            return;
        }

        header('Content-Type: ' . self::TYPES[pathinfo($file, PATHINFO_EXTENSION)]);
        header('Cache-Control: public, max-age=31536000, immutable');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }

    private function uploadDir(): string
    {
        $config = $this->app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/dating-photos';
    }
}
