<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VisitorCard extends Model
{
    use HasFactory, HasUuidV7, SoftDeletes, LogsActivity;

    protected $fillable = ['card_no', 'status'];

    public function visits()
    {
        return $this->belongsToMany(Visit::class)->using(VisitVisitorCard::class)->withTimestamps();
    }
}
