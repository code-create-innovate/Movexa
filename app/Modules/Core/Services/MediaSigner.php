<?php

namespace App\Modules\Core\Services;

/**
 * Short-lived, user-bound tokens embedded in the URL PATH (/m/{token}/{file}) so relative URIs
 * inside HLS playlists (segments, variants) inherit the token automatically.
 * The token alone is never enough: the request must also carry a valid session for the same user.
 */
class MediaSigner
{
    public const KINDS = ['file', 'hls', 'sub', 'img', 'thumb', 'live', 'rec', 'dl'];

    public function sign(string $kind, int|string $id, int $userId, ?int $profileId = null, ?int $ttl = null): string
    {
        $payload = [
            'k' => $kind,
            'i' => (string) $id,
            'u' => $userId,
            'p' => $profileId,
            'e' => time() + ($ttl ?? config('movexa.signed_url_ttl')),
        ];
        $body = $this->b64(json_encode($payload));

        return $body.'.'.$this->b64($this->mac($body));
    }

    public function url(string $kind, int|string $id, int $userId, ?int $profileId = null, string $file = 'stream', ?int $ttl = null): string
    {
        return '/m/'.$this->sign($kind, $id, $userId, $profileId, $ttl).'/'.$file;
    }

    /** @return array{k:string,i:string,u:int,p:?int,e:int}|null */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;
        if (! hash_equals($this->b64($this->mac($body)), $sig)) {
            return null;
        }
        $payload = json_decode($this->unb64($body), true);
        if (! is_array($payload) || ! in_array($payload['k'] ?? null, self::KINDS, true) || ($payload['e'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    private function mac(string $body): string
    {
        $key = config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return hash_hmac('sha256', 'movexa-media|'.$body, $key, true);
    }

    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }
}
