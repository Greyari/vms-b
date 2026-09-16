<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class ImportMapping extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $fillable = ['source', 'entity', 'source_key', 'target_id', 'imported_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }
}
