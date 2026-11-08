<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;

class Upload extends Model
{
    protected $fillable = ['user_id', 'library_id', 'uuid', 'file_name', 'size', 'received', 'chunk_size', 'completed'];

    protected function casts(): array
    {
        return ['completed' => 'boolean'];
    }

    public function partPath(): string
    {
        return rtrim(config('movexa.paths.uploads'), '/').'/'.$this->uuid.'.part';
    }
}
