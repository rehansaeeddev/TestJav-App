<?php

namespace App\Models;

use Database\Factories\HelpArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A how-to entry written in our own words, linking to the official Shopify Help Center article.
 */
#[Fillable(['slug', 'title', 'admin_page', 'admin_path', 'page_summary', 'steps', 'keywords', 'source_url'])]
#[Hidden(['embedding'])]
class HelpArticle extends Model
{
    /** @use HasFactory<HelpArticleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }

    /**
     * Words that carry no meaning for search. Generic words like "shopify" or "help" appear in
     * many articles, so on their own they would match an unrelated guide.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'can', 'do', 'does', 'for', 'how', 'i', 'in', 'is', 'it',
        'me', 'my', 'of', 'on', 'or', 'please', 'the', 'this', 'that', 'to', 'what', 'where', 'you',
        'shopify', 'admin', 'store', 'shop', 'help', 'want', 'need', 'know', 'get', 'use', 'way', 'with', 'from',
        'make', 'change', 'see', 'set', 'up', 'open', 'go', 'new', 'should', 'would', 'could', 'we',
        'then', 'now', 'ok', 'okay', 'also', 'just', 'so', 'there', 'here', 'next',
    ];

    /**
     * The guide for an admin page named by the merchant ("finance", "inventory", "orders"):
     * exact page name first, then pages starting with it, then the shortest page name.
     * The subject only contains letters and spaces (see MessageParser), so LIKE is safe.
     */
    public static function forPageNamed(string $subject): ?self
    {
        $name = Str::singular(mb_strtolower(trim($subject)));

        if (mb_strlen($name) < 3) {
            return null;
        }

        return static::query()
            ->where(fn (Builder $query) => $query->whereRaw('LOWER(admin_page) LIKE ?', ["%{$name}%"])->orWhereRaw('LOWER(title) LIKE ?', ["%{$name}%"]))
            /** A guide about the whole section ("Content section: …") beats one page inside it ("Content → Menus"). */
            ->orderByRaw('CASE WHEN LOWER(admin_page) IN (?, ?) OR LOWER(title) IN (?, ?) OR LOWER(title) LIKE ? THEN 0 WHEN LOWER(admin_page) LIKE ? THEN 1 ELSE 2 END',
                [$name, Str::plural($name), $name, Str::plural($name), "{$name} section%", "{$name}%"])
            ->orderByRaw('LENGTH(admin_page)')
            ->first();
    }

    /**
     * Keyword search over the FTS5 index, best match first.
     */
    #[Scope]
    protected function search(Builder $query, string $text, bool $namesOnly = false): Builder
    {
        $match = self::toMatchExpression($text);

        if ($match === null) {
            return $query->whereRaw('0 = 1');
        }

        /** Names only: match title and keywords, not the long text where words like "page" appear everywhere. */
        if ($namesOnly) {
            $match = "{title keywords} : ({$match})";
        }

        return $query
            ->select('help_articles.*')
            ->join('help_articles_fts', 'help_articles_fts.rowid', '=', 'help_articles.id')
            ->whereRaw('help_articles_fts MATCH ?', [$match])
            ->orderByRaw('bm25(help_articles_fts, 10.0, 5.0, 1.0, 1.0)');
    }

    /**
     * The best guide only if it really is about the question: at least half of the question's
     * words must appear in its title, page name or keywords. One shared word ("payment",
     * "page") is not enough, because showing the wrong guide is worse than saying we're unsure.
     */
    public static function confidentMatch(string $text): ?self
    {
        $words = self::contentWords($text);

        if ($words === []) {
            return null;
        }

        /** Of the top 3 by rank, the one covering the most words wins (sorting is stable, so rank breaks ties). */
        return static::query()->search($text)->limit(3)->get()
            ->sortByDesc(fn (self $article) => $article->nameCoverage($words))
            ->first(fn (self $article) => $article->nameCoverage($words) >= 0.5);
    }

    /**
     * Share of the words found in this guide's title, page name or keywords. Words are compared
     * by their first 5 letters so "payouts" still finds "payout" and "refunding" finds "refund".
     *
     * @param  list<string>  $words
     */
    public function nameCoverage(array $words): float
    {
        if ($words === []) {
            return 0.0;
        }

        $names = mb_strtolower("{$this->title} {$this->admin_page} {$this->keywords}");
        $found = collect($words)->filter(fn (string $word) => str_contains($names, mb_substr($word, 0, 5)))->count();

        return $found / count($words);
    }

    /**
     * @return list<string>
     */
    public static function contentWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return collect($matches[0])
            ->reject(fn (string $word) => in_array($word, self::STOP_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Turn free text into a safe FTS5 expression: each word is quoted so FTS operators
     * typed by a user ("NEAR", "*", "-", column filters) are treated as plain words.
     */
    public static function toMatchExpression(string $text): ?string
    {
        $words = collect(self::contentWords($text))->map(fn (string $word) => '"'.$word.'"');

        return $words->isEmpty() ? null : $words->implode(' OR ');
    }
}
