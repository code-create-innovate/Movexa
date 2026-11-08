<?php

namespace App\Modules\Library\Services\Security;

final class ScanResult
{
    public function __construct(
        public readonly bool $clean,
        public readonly ?string $threat = null,
        public readonly ?string $error = null,
        public readonly string $engine = 'unknown',
    ) {}

    public static function clean(string $engine): self
    {
        return new self(true, engine: $engine);
    }

    public static function infected(string $threat, string $engine): self
    {
        return new self(false, $threat, engine: $engine);
    }

    public static function error(string $error, string $engine): self
    {
        return new self(false, null, $error, $engine);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
