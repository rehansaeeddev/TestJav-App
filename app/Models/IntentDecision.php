<?php

namespace App\Models;

use App\Enums\ChatIntent;
use Database\Factories\IntentDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one question in a merchant message was routed: Laya's raw answer, the rules that
 * fired after it (decided_by, e.g. "laya > no_policy_word"), and what the answer was about.
 */
#[Fillable(['part', 'question', 'final_intent', 'decided_by', 'target', 'answered_by', 'laya_choice', 'laya_confidence', 'laya_probabilities', 'laya_ms', 'search_score', 'llm_confidence', 'error'])]
class IntentDecision extends Model
{
    /** @use HasFactory<IntentDecisionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'final_intent' => ChatIntent::class,
            'laya_choice' => ChatIntent::class,
            'laya_confidence' => 'float',
            'laya_probabilities' => 'array',
            'search_score' => 'float',
            'llm_confidence' => 'float',
        ];
    }

    /**
     * The merchant message the question came from.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
