<?php

namespace App\Services\Chat;

use App\Enums\ChatIntent;

/**
 * How one question was routed, for the decision log. $steps lists who decided, in order:
 * "laya", then each rule that changed Laya's answer; or a single rule that ran before Laya.
 */
final readonly class RoutingDecision
{
    /**
     * @param  list<string>  $steps
     * @param  array<string, float>  $layaProbabilities
     */
    public function __construct(
        public ?ChatIntent $intent,
        public array $steps,
        public ?ChatIntent $layaChoice = null,
        public ?float $layaConfidence = null,
        public array $layaProbabilities = [],
        public ?int $layaMs = null,
        public ?string $error = null,
    ) {}

    public static function byRule(?ChatIntent $intent, string $rule): self
    {
        return new self($intent, [$rule]);
    }

    /**
     * The same decision with one more step that changed the final intent.
     */
    public function overruledBy(ChatIntent $intent, string $rule): self
    {
        return new self($intent, [...$this->steps, $rule], $this->layaChoice, $this->layaConfidence,
            $this->layaProbabilities, $this->layaMs, $this->error);
    }

    public function decidedBy(): string
    {
        return implode(' > ', $this->steps);
    }
}
