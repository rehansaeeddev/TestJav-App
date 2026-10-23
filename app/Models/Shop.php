<?php

namespace App\Models;

use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A store that installed the app. Tokens are encrypted at rest with APP_KEY,
 * so rotating APP_KEY means every shop must re-authorize.
 */
#[Fillable([
    'domain',
    'access_token',
    'access_token_expires_at',
    'refresh_token',
    'refresh_token_expires_at',
    'scopes',
    'timezone',
])]
#[Hidden(['access_token', 'refresh_token'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token' => 'encrypted',
            'refresh_token_expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
