<?php

namespace App\Modules\Library\Services\Security;

/** Runs every configured engine; any detection or error = not clean. */
class MultiScanner implements VirusScanner
{
    /** @param list<VirusScanner> $engines */
    public function __construct(private array $engines) {}

    public function name(): string
    {
        return implode('+', array_map(fn ($e) => $e->name(), $this->engines));
    }

    public function available(): bool
    {
        foreach ($this->engines as $e) {
            if (! $e->available()) {
                return false;
            }
        }

        return $this->engines !== [];
    }

    public function scan(string $path): ScanResult
    {
        if ($this->engines === []) {
            return ScanResult::error('no scanner configured', 'none'); // never "clean" by default
        }
        foreach ($this->engines as $e) {
            $r = $e->scan($path);
            if (! $r->clean) {
                return $r;
            }
        }

        return ScanResult::clean($this->name());
    }
}
