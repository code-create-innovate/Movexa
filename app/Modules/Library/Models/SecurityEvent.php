<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;

/** Audit log of every scanned file: name, hash, stage, result. Never the file content. */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ingest_job_id', 'file_name', 'sha256', 'stage', 'result', 'detail'];
}
