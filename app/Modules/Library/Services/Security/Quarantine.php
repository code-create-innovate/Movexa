<?php

namespace App\Modules\Library\Services\Security;

use Illuminate\Support\Str;
use RuntimeException;

/** Encrypted at rest (XChaCha20-Poly1305 secretstream) so a quarantined file can never be executed or served. */
class Quarantine
{
    public function put(string $path): string
    {
        $dir = config('movexa.paths.quarantine');
        @mkdir($dir, 0750, true);
        $dest = $dir.'/'.Str::uuid().'.qbin';
        $in = fopen($path, 'rb');
        $out = fopen($dest, 'wb');
        if (! $in || ! $out) {
            throw new RuntimeException('quarantine io error');
        }
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key());
        fwrite($out, $header);
        while (! feof($in)) {
            $chunk = (string) fread($in, 1 << 16);
            $tag = feof($in) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $enc = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            fwrite($out, pack('N', strlen($enc)).$enc);
        }
        fclose($in);
        fclose($out);
        @unlink($path);
        @chmod($dest, 0600);

        return $dest;
    }

    /** Decrypt to a temp path (used only for "retry sanitize"). */
    public function restoreTo(string $qpath, string $dest): void
    {
        $in = fopen($qpath, 'rb');
        $out = fopen($dest, 'wb');
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $this->key());
        while (($len = fread($in, 4)) !== false && strlen($len) === 4) {
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, fread($in, unpack('N', $len)[1]));
            if ($res === false) {
                throw new RuntimeException('quarantine decrypt failed');
            }
            fwrite($out, $res[0]);
        }
        fclose($in);
        fclose($out);
    }

    private function key(): string
    {
        $k = config('app.key');
        $k = str_starts_with($k, 'base64:') ? base64_decode(substr($k, 7)) : $k;

        return hash_hkdf('sha256', $k, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'movexa-quarantine');
    }
}
