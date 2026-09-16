<?php

namespace App\Models;

use App\Enums\CardType;
use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VisitorCard extends Model
{
    use HasFactory, HasUuidV7, LogsActivity, SoftDeletes;

    protected $fillable = ['card_no', 'status', 'absenteeism_no', 'card_type', 'note', 'is_active'];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'card_type' => CardType::class,
        ];
    }

    public function visits()
    {
        return $this->belongsToMany(Visit::class)->using(VisitVisitorCard::class)->withTimestamps();
    }
}
