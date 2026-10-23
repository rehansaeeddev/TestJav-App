<?php

namespace App\Services\Shopify;

/**
 * Which products the merchant asked about: "under $25", "out of stock", "draft", "low in stock".
 * Status and stock go to Shopify's product search; price is checked on each variant here, because
 * Shopify documents only exact-price search.
 */
final readonly class ProductFilter
{
    /**
     * @param  'in'|'out'|'low'|null  $stock  "low": some tracked variant has fewer than $fewerThan units.
     * @param  'active'|'draft'|'archived'|null  $status
     * @param  int|null  $fewerThan  For "low"; null means the app's usual low-stock level.
     */
    public function __construct(
        public ?float $minPrice = null,
        public ?float $maxPrice = null,
        public ?string $stock = null,
        public ?string $status = null,
        public ?int $fewerThan = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->minPrice === null && $this->maxPrice === null && $this->stock === null && $this->status === null;
    }

    /**
     * The part Shopify's product search can do, e.g. "status:draft AND inventory_total:0".
     */
    public function query(): ?string
    {
        $parts = array_filter([
            $this->status !== null ? "status:{$this->status}" : null,
            match ($this->stock) {
                'out' => 'inventory_total:0',
                'in' => 'inventory_total:>0',
                default => null,
            },
        ]);

        return $parts === [] ? null : implode(' AND ', $parts);
    }

    /**
     * Price check on the variants: a product counts when any variant's price fits.
     *
     * @param  array{variants: list<array{price: string}>}  $product
     */
    public function priceFits(array $product): bool
    {
        return collect($product['variants'])->contains(fn (array $variant) => ($this->minPrice === null || (float) $variant['price'] > $this->minPrice)
            && ($this->maxPrice === null || (float) $variant['price'] < $this->maxPrice));
    }

    /**
     * Words for the reply: "draft products under 25 that are low in stock".
     */
    public function label(): string
    {
        $after = array_filter([
            $this->minPrice !== null ? 'over '.$this->money($this->minPrice) : null,
            $this->maxPrice !== null ? 'under '.$this->money($this->maxPrice) : null,
            match ($this->stock) {
                'in' => 'in stock',
                'out' => 'out of stock',
                'low' => $this->fewerThan !== null ? "with fewer than {$this->fewerThan} left" : 'low in stock',
                null => null,
            },
        ]);

        return trim(($this->status !== null ? "{$this->status} " : '').'products '.implode(', ', $after));
    }

    private function money(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }
}
