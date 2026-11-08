<?php

namespace App\Modules\Library\Services\Security;

/** Unified interface so a second engine can be added later (e.g. via MultiScanner). */
interface VirusScanner
{
    public function name(): string;

    public function available(): bool;

    public function scan(string $path): ScanResult;
}
