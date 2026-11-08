<?php

namespace Tests\Unit\Security;

use App\Modules\Library\Services\Security\SubtitleSanitizer;
use PHPUnit\Framework\TestCase;

class SubtitleSanitizerTest extends TestCase
{
    public function test_windows_1256_arabic_is_converted_to_utf8(): void
    {
        $utf8 = "1\n00:00:01,000 --> 00:00:03,500\nمرحباً بكم في الفيلم\n\n2\n00:00:04,000 --> 00:00:06,000\nكيف حالك؟\n";
        $cp1256 = iconv('UTF-8', 'WINDOWS-1256', $utf8);
        $this->assertFalse(mb_check_encoding($cp1256, 'UTF-8'));

        $r = (new SubtitleSanitizer)->sanitize($cp1256, 'srt');
        $this->assertSame('Windows-1256', $r['encoding']);
        $this->assertSame(2, $r['cues']);
        $this->assertStringContainsString('مرحباً بكم في الفيلم', $r['vtt']);
        $this->assertStringContainsString('كيف حالك؟', $r['vtt']);
        $this->assertStringContainsString('00:00:01.000 --> 00:00:03.500', $r['vtt']);
        $this->assertTrue(mb_check_encoding($r['vtt'], 'UTF-8'));
    }

    public function test_scripts_html_and_links_are_removed(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\n<script>alert('x')</script><b>Hi</b> <a href=\"http://evil\">click</a>\n\n"
            ."2\n00:00:02,000 --> 00:00:03,000\n&lt;img src=x onerror=alert(1)&gt;Safe text javascript:alert(1)\n\n"
            ."3\n00:00:03,000 --> 00:00:04,000\n<iframe src=//x></iframe>visit https://phish.example/login now\n";
        $r = (new SubtitleSanitizer)->sanitize($srt);
        $this->assertSame(3, $r['cues']);
        $text = preg_replace('/^.*-->.*$/m', '', $r['vtt']);
        foreach (['<', '>', 'script', 'alert(', 'href', 'http', 'javascript:', 'iframe', 'onerror'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $text);
        }
        $this->assertStringContainsString('Hi click', $r['vtt']);
        $this->assertStringContainsString('Safe text', $r['vtt']);
        $this->assertStringContainsString('visit now', $r['vtt']);
    }

    public function test_ass_is_converted_without_override_codes(): void
    {
        $ass = "[Script Info]\nTitle: x\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n"
            ."Dialogue: 0,0:00:01.50,0:00:03.00,Default,,0,0,0,,{\\an8\\c&H00FF00&}مرحبا\\Nسطر ثانٍ\n";
        $r = (new SubtitleSanitizer)->sanitize($ass, 'ass');
        $this->assertSame(1, $r['cues']);
        $this->assertStringContainsString("00:00:01.500 --> 00:00:03.000\nمرحبا\nسطر ثانٍ", $r['vtt']);
        $this->assertStringNotContainsString('\\an8', $r['vtt']);
    }

    public function test_utf16_and_bom(): void
    {
        $text = "1\n00:00:01,000 --> 00:00:02,000\nعربي\n";
        $r = (new SubtitleSanitizer)->sanitize("\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));
        $this->assertStringContainsString('عربي', $r['vtt']);
        $r = (new SubtitleSanitizer)->sanitize("\xEF\xBB\xBF".$text);
        $this->assertSame('UTF-8', $r['encoding']);
    }
}
