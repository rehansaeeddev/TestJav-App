<?php

namespace App\Models;

use App\Enums\AwaitingAnswer;
use App\Enums\ChatIntent;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat thread between a merchant's staff member and the bot.
 * Holds the bot's short-term memory: what follow-up answer it is waiting for,
 * which admin page it last sent the merchant to, and in $context the last order, products and
 * policy it showed (see ChatOrchestrator::remember()).
 */
#[Fillable(['staff_user_id', 'awaiting', 'pending_intent', 'last_help_article_id', 'context'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'awaiting' => AwaitingAnswer::class,
            'pending_intent' => ChatIntent::class,
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return BelongsTo<HelpArticle, $this>
     */
    public function lastHelpArticle(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'last_help_article_id');
    }
}
