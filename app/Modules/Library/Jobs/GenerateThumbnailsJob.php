<?php

namespace App\Modules\Library\Jobs;

use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Services\ThumbnailGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateThumbnailsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 3600;

    public function __construct(public int $fileId)
    {
        $this->onQueue('scan');
    }

    public function handle(ThumbnailGenerator $gen): void
    {
        if ($file = MediaFile::find($this->fileId)) {
            $gen->generate($file);
        }
    }
}
