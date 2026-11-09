<?php

namespace App\Modules\Library\Services\Security;

use RuntimeException;

/** Re-encodes any image to a brand-new WebP (or JPEG) — drops EXIF, ICC payloads and appended data. */
class ImageSanitizer
{
    public function sanitize(string $source, string $dest, int $maxWidth = 1920): string
    {
        $data = @file_get_contents($source);
        $img = $data !== false ? @imagecreatefromstring($data) : false;
        if (! $img) {
            throw new RuntimeException('invalid image');
        }
        $w = imagesx($img);
        if ($w > $maxWidth) {
            $img = imagescale($img, $maxWidth) ?: $img;
        }
        $useWebp = function_exists('imagewebp');
        $dest = preg_replace('/\.[a-z0-9]+$/i', '', $dest).($useWebp ? '.webp' : '.jpg');
        @mkdir(dirname($dest), 0755, true);
        $ok = $useWebp ? imagewebp($img, $dest, 82) : imagejpeg($img, $dest, 85);
        if (! $ok) {
            throw new RuntimeException('image encode failed');
        }

        return $dest;
    }
}
