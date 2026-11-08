<?php

namespace Tests\Unit\Core;

use App\Modules\Core\Services\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function test_rfc6238_vector(): void
    {
        // RFC 6238 Appendix B, SHA1 secret "12345678901234567890", T=59 → 94287082 (last 6 digits).
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1111111109));
        $this->assertTrue(Totp::verify($secret, '287082', 1, 59));
        $this->assertFalse(Totp::verify($secret, '000000', 1, 59));
    }

    public function test_base32_roundtrip(): void
    {
        $raw = random_bytes(20);
        $this->assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
    }
}
