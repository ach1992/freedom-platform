<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

final class User extends Authenticatable
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::creating(function (self $user): void {
            if (! is_string($user->public_id) || $user->public_id === '') {
                $user->public_id = (string) Str::ulid();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
