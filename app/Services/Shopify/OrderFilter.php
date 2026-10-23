<?php

namespace App\Services\Shopify;

/**
 * Which orders the merchant asked about: "unfulfilled", "unpaid", "over $100", "used WELCOME10",
 * "high risk", "excluding cancelled". Built by MessageParser::orderFilter() from fixed patterns, so
 * the merchant's own words never reach Shopify's search, only these known fields.
 */
final readonly class OrderFilter
{
    /**
     * @param  'unfulfilled'|'fulfilled'|'partial'|null  $fulfillment
     * @param  'paid'|'pending'|'refunded'|null  $payment
     * @param  bool|null  $cancelled  true: only cancelled orders; false: leave cancelled ones out.
     * @param  'largest'|'smallest'|null  $sort  By order total instead of newest first.
     */
    public function __construct(
        public ?string $fulfillment = null,
        public ?string $payment = null,
        public ?bool $cancelled = null,
        public ?float $minTotal = null,
        public ?float $maxTotal = null,
        public ?string $discountCode = null,
        public bool $highRisk = false,
        public ?string $sort = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->query() === null && $this->sort === null;
    }

    /**
     * The Shopify order search filter, e.g. "fulfillment_status:unfulfilled AND current_total_price:>100".
     */
    public function query(): ?string
    {
        $parts = array_filter([
            $this->fulfillment !== null ? "fulfillment_status:{$this->fulfillment}" : null,
            $this->payment !== null ? "financial_status:{$this->payment}" : null,
            match ($this->cancelled) {
                true => 'status:cancelled',
                false => '-status:cancelled',
                null => null,
            },
            $this->minTotal !== null ? 'current_total_price:>'.$this->minTotal : null,
            $this->maxTotal !== null ? 'current_total_price:<'.$this->maxTotal : null,
            $this->discountCode !== null ? "discount_code:{$this->discountCode}" : null,
            $this->highRisk ? 'risk_level:high' : null,
        ]);

        return $parts === [] ? null : implode(' AND ', $parts);
    }

    /**
     * Words for the reply: "unfulfilled, unpaid orders over 100".
     */
    public function label(): string
    {
        $before = array_filter([
            $this->highRisk ? 'high-risk' : null,
            match ($this->fulfillment) {
                'partial' => 'partially fulfilled',
                null => null,
                default => $this->fulfillment,
            },
            match ($this->payment) {
                'pending' => 'unpaid',
                null => null,
                default => $this->payment,
            },
            $this->cancelled === true ? 'cancelled' : null,
        ]);

        $after = array_filter([
            $this->minTotal !== null ? 'over '.$this->money($this->minTotal) : null,
            $this->maxTotal !== null ? 'under '.$this->money($this->maxTotal) : null,
            $this->discountCode !== null ? "that used {$this->discountCode}" : null,
            $this->cancelled === false ? 'excluding cancelled ones' : null,
        ]);

        return trim(implode(', ', $before).' orders '.implode(' ', $after));
    }

    private function money(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }
}
