<?php

namespace App\Models;

use App\Support\Username;
use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'username', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUuidV7, LogsActivity, Notifiable;

    /**
     * password/remember_token are always excluded by the trait already;
     * email_verified_at is just noise for this log.
     */
    protected array $logExcept = ['email_verified_at'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Stored upper-cased (App\Support\Username), so a login typed in any case
     * matches the account.
     */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => Username::normalize($value));
    }
}
