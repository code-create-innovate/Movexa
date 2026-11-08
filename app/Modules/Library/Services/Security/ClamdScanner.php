<?php

namespace App\Modules\Library\Services\Security;

/** Streams the file to clamd over INSTREAM (unix socket or TCP). clamd never needs read access to our dirs. */
class ClamdScanner implements VirusScanner
{
    public function __construct(private ?string $socket = null, private ?string $host = null) {}

    public function name(): string
    {
        return 'clamav';
    }

    private function connect()
    {
        $host = $this->host ?? config('movexa.security.clamd_host');
        $target = $host ? 'tcp://'.$host : 'unix://'.($this->socket ?? config('movexa.security.clamd_socket'));
        $s = @stream_socket_client($target, $errno, $errstr, 5);
        if ($s) {
            stream_set_timeout($s, 600);
        }

        return $s ?: null;
    }

    public function available(): bool
    {
        $s = $this->connect();
        if (! $s) {
            return false;
        }
        fwrite($s, "zPING\0");
        $r = trim((string) fread($s, 64), "\0\n ");
        fclose($s);

        return $r === 'PONG';
    }

    public function version(): ?string
    {
        $s = $this->connect();
        if (! $s) {
            return null;
        }
        fwrite($s, "zVERSION\0");
        $r = trim((string) stream_get_contents($s), "\0\n ");
        fclose($s);

        return $r ?: null;
    }

    public function scan(string $path): ScanResult
    {
        $s = $this->connect();
        if (! $s) {
            return ScanResult::error('clamd unreachable', $this->name());
        }
        $fh = fopen($path, 'rb');
        if (! $fh) {
            fclose($s);

            return ScanResult::error('cannot read file', $this->name());
        }
        fwrite($s, "zINSTREAM\0");
        while (! feof($fh)) {
            $chunk = fread($fh, 1 << 16);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            fwrite($s, pack('N', strlen($chunk)).$chunk);
        }
        fwrite($s, pack('N', 0));
        fclose($fh);
        $reply = trim((string) stream_get_contents($s), "\0\n ");
        fclose($s);

        // "stream: OK" | "stream: Eicar-Signature FOUND" | "INSTREAM size limit exceeded. ERROR"
        if (str_ends_with($reply, ': OK')) {
            return ScanResult::clean($this->name());
        }
        if (preg_match('/:\s(.+)\sFOUND$/', $reply, $m)) {
            return ScanResult::infected($m[1], $this->name());
        }

        return ScanResult::error($reply ?: 'empty reply', $this->name());
    }
}
