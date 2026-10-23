<?php

namespace App\Models;

use App\Enums\ChatIntent;
use App\Enums\MessageRole;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['role', 'content', 'intent', 'admin_path', 'link_label', 'help_url', 'answered_by', 'confidence', 'feedback'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'intent' => ChatIntent::class,
            'confidence' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * How each question in this merchant message was routed.
     *
     * @return HasMany<IntentDecision, $this>
     */
    public function intentDecisions(): HasMany
    {
        return $this->hasMany(IntentDecision::class);
    }
}
