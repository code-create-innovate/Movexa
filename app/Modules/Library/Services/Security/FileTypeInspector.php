<?php

namespace App\Modules\Library\Services\Security;

use finfo;

/**
 * Determines the REAL type from content (libmagic), never from the extension alone.
 * Rejects executables, scripts, archives, disguised files ("movie.mp4.exe", exe renamed to .mp4).
 */
class FileTypeInspector
{
    private const FORBIDDEN_EXTENSIONS = ['exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'pif', 'sh', 'bash', 'ps1', 'vbs', 'js', 'jar', 'apk', 'elf', 'bin', 'php', 'phtml', 'py', 'pl', 'rb', 'lnk', 'iso', 'zip', 'rar', '7z', 'tar', 'gz', 'xz', 'html', 'htm', 'svg', 'hta', 'cpl', 'deb', 'rpm', 'appimage', 'dmg'];

    // Content signatures that are never acceptable anywhere inside the first bytes.
    private const MAGIC_DENY = [
        "MZ" => 'windows_executable', "\x7FELF" => 'elf_executable', "#!" => 'script',
        "PK\x03\x04" => 'zip_archive', "Rar!" => 'rar_archive', "7z\xBC\xAF" => '7z_archive',
        "\xCA\xFE\xBA\xBE" => 'macho_or_java', "\xCF\xFA\xED\xFE" => 'macho', "<?php" => 'php_script',
        "\x1F\x8B" => 'gzip_archive',
    ];

    /** @return array{ok:bool, category:?string, mime:?string, reason:?string} */
    public function inspect(string $path, string $originalName): array
    {
        $name = strtolower(basename($originalName));
        $parts = explode('.', $name);
        $ext = count($parts) > 1 ? end($parts) : '';

        // Double / hidden extensions: any forbidden segment anywhere in the name.
        foreach (array_slice($parts, 1) as $seg) {
            if (in_array($seg, self::FORBIDDEN_EXTENSIONS, true)) {
                return $this->deny('forbidden_extension:'.$seg);
            }
        }

        $category = $this->categoryForExtension($ext);
        if (! $category) {
            return $this->deny('extension_not_allowed:'.($ext ?: 'none'));
        }

        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return $this->deny('unreadable');
        }
        $head = (string) fread($fh, 4096);
        fclose($fh);
        if ($head === '') {
            return $this->deny('empty_file');
        }

        foreach (self::MAGIC_DENY as $magic => $label) {
            if (str_starts_with($head, $magic)) {
                return $this->deny('disguised_'.$label);
            }
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        $ok = match ($category) {
            'video' => str_starts_with($mime, 'video/') || in_array($mime, ['application/octet-stream', 'audio/x-matroska', 'application/x-matroska'], true) && $this->looksLikeContainer($head),
            'audio' => str_starts_with($mime, 'audio/') || ($mime === 'application/octet-stream' && $this->looksLikeContainer($head)),
            'subtitle' => str_starts_with($mime, 'text/') || $mime === 'application/x-subrip' || $mime === 'application/octet-stream' && ! preg_match('/[\x00-\x08\x0E-\x1A]/', $head),
            'image' => in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true),
            default => false,
        };
        if (! $ok) {
            return $this->deny("content_mismatch:{$category}:{$mime}", $mime);
        }
        return ['ok' => true, 'category' => $category, 'mime' => $mime, 'reason' => null];
    }

    public function categoryForExtension(string $ext): ?string
    {
        foreach (config('movexa.security.allowed_extensions') as $cat => $exts) {
            if (in_array($ext, $exts, true)) {
                return $cat;
            }
        }

        return null;
    }

    private function looksLikeContainer(string $head): bool
    {
        return str_starts_with($head, "\x1A\x45\xDF\xA3")      // EBML (mkv/webm)
            || substr($head, 4, 4) === 'ftyp'                    // mp4/mov
            || str_starts_with($head, 'RIFF')                    // avi/wav
            || $head[0] === "\x47"                               // mpeg-ts sync byte
            || str_starts_with($head, "\x00\x00\x01\xBA")        // mpeg-ps
            || str_starts_with($head, 'ID3') || str_starts_with($head, 'fLaC') || str_starts_with($head, 'OggS');
    }

    private function deny(string $reason, ?string $mime = null): array
    {
        return ['ok' => false, 'category' => null, 'mime' => $mime, 'reason' => $reason];
    }
}
