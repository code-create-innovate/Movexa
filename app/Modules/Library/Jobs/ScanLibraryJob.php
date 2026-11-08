<?php

namespace App\Modules\Library\Jobs;

use App\Modules\Library\Models\Library;
use App\Modules\Library\Services\LibraryScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ScanLibraryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public function __construct(public int $libraryId)
    {
        $this->onQueue('scan');
    }

    public function uniqueId(): string
    {
        return (string) $this->libraryId;
    }

    public function handle(LibraryScanner $scanner): void
    {
        if ($lib = Library::find($this->libraryId)) {
            $scanner->scan($lib);
        }
    }
}
