<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VisitorCard extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = ['card_no', 'status'];

    public function visits()
    {
        return $this->belongsToMany(Visit::class)->using(VisitVisitorCard::class);
    }
}
