<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * HomeCore SSO: OpenID Connect authorization code flow with PKCE (S256).
 * Movexa keeps its own users table; HomeCore identities link via `oidc_subject`.
 * If HomeCore is down, existing sessions keep working and the local emergency admin can log in.
 */
class OidcController extends Controller
{
    public function redirect(Request $request)
    {
        abort_unless(config('movexa.homecore.oidc.enabled'), 404);
        $conf = $this->discovery();
        abort_unless($conf, 503, 'HomeCore unavailable');

        $verifier = Str::random(64);
        $state = Str::random(40);
        $nonce = Str::random(40);
        $request->session()->put('oidc', compact('verifier', 'state', 'nonce'));

        return redirect()->away($conf['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('movexa.homecore.oidc.client_id'),
            'redirect_uri' => config('movexa.homecore.oidc.redirect'),
            'scope' => config('movexa.homecore.oidc.scopes'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request)
    {
        abort_unless(config('movexa.homecore.oidc.enabled'), 404);
        $saved = $request->session()->pull('oidc');
        abort_unless($saved && hash_equals($saved['state'], (string) $request->query('state')), 403, 'invalid state');
        abort_if($request->has('error'), 403, 'login refused');
        $conf = $this->discovery();
        abort_unless($conf, 503);

        $tokens = Http::asForm()->timeout(10)->post($conf['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $request->query('code'),
            'redirect_uri' => config('movexa.homecore.oidc.redirect'),
            'client_id' => config('movexa.homecore.oidc.client_id'),
            'client_secret' => config('movexa.homecore.oidc.client_secret'),
            'code_verifier' => $saved['verifier'],
        ]);
        abort_unless($tokens->successful(), 403, 'token exchange failed');

        $claims = $this->idTokenClaims((string) $tokens->json('id_token'), $conf, $saved['nonce']);
        $info = Http::withToken($tokens->json('access_token'))->timeout(10)->get($conf['userinfo_endpoint'])->json() ?: [];
        abort_unless(($info['sub'] ?? null) === $claims['sub'], 403, 'subject mismatch');

        $user = User::where('oidc_subject', $claims['sub'])->first()
            ?? (isset($info['email']) && ($info['email_verified'] ?? true) ? User::where('email', $info['email'])->whereNull('oidc_subject')->first() : null);
        if (! $user) {
            $user = User::create([
                'name' => $info['name'] ?? $info['preferred_username'] ?? 'User',
                'email' => $info['email'] ?? $claims['sub'].'@homecore.local',
                'password' => Str::random(48),
                'is_admin' => in_array('movexa-admin', (array) ($info['groups'] ?? []), true),
            ]);
        }
        $user->forceFill(['oidc_subject' => $claims['sub']])->save();

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    private function discovery(): ?array
    {
        $issuer = rtrim((string) config('movexa.homecore.oidc.issuer'), '/');

        return Cache::remember('oidc.discovery', 3600, function () use ($issuer) {
            $r = rescue(fn () => Http::timeout(5)->get($issuer.'/.well-known/openid-configuration'), null, false);

            return $r?->successful() ? $r->json() : null;
        }) ?: null;
    }

    /** Validates RS256 id_token signature against the issuer's JWKS, plus iss/aud/exp/nonce. */
    private function idTokenClaims(string $jwt, array $conf, string $nonce): array
    {
        $parts = explode('.', $jwt);
        abort_unless(count($parts) === 3, 403);
        [$h, $p, $s] = $parts;
        $dec = fn ($x) => base64_decode(strtr($x, '-_', '+/').str_repeat('=', (4 - strlen($x) % 4) % 4));
        $header = json_decode($dec($h), true);
        $claims = json_decode($dec($p), true);
        abort_unless(($header['alg'] ?? '') === 'RS256', 403, 'alg');

        $jwks = Cache::remember('oidc.jwks', 3600, fn () => Http::timeout(5)->get($conf['jwks_uri'])->json('keys') ?? []);
        $jwk = collect($jwks)->first(fn ($k) => ($k['kid'] ?? null) === ($header['kid'] ?? null)) ?? ($jwks[0] ?? null);
        abort_unless($jwk && isset($jwk['n'], $jwk['e']), 403, 'jwks');
        $pem = self::jwkToPem($dec($jwk['n']), $dec($jwk['e']));
        abort_unless(openssl_verify("$h.$p", $dec($s), $pem, OPENSSL_ALGO_SHA256) === 1, 403, 'signature');

        $aud = (array) ($claims['aud'] ?? []);
        abort_unless(
            rtrim($claims['iss'] ?? '', '/') === rtrim($conf['issuer'], '/')
            && in_array(config('movexa.homecore.oidc.client_id'), $aud, true)
            && ($claims['exp'] ?? 0) > time() - 60
            && hash_equals($nonce, (string) ($claims['nonce'] ?? '')),
            403, 'claims');

        return $claims;
    }

    public static function jwkToPem(string $n, string $e): string
    {
        $int = fn ($x) => "\x02".self::len(ltrim($x, "\x00") === '' ? "\x00" : (ord(ltrim($x, "\x00")[0]) > 0x7F ? "\x00".ltrim($x, "\x00") : ltrim($x, "\x00")));
        $rsa = "\x30".self::len($int($n).$int($e));
        $algo = "\x30\x0D\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $spki = "\x30".self::len($algo."\x03".self::len("\x00".$rsa));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64)."-----END PUBLIC KEY-----\n";
    }

    private static function len(string $body): string
    {
        $l = strlen($body);
        if ($l < 0x80) {
            return chr($l).$body;
        }
        $b = ltrim(pack('N', $l), "\x00");

        return chr(0x80 | strlen($b)).$b.$body;
    }
}
