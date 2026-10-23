<?php

namespace App\Services\Chat;

use App\Models\HelpArticle;
use App\Services\EmbeddingClient;
use Illuminate\Support\Collection;

/**
 * Meaning-based guide search (the R in RAG): the question and every guide are vectors, and the
 * guides pointing most nearly the same way as the question come first. Score: cosine similarity, 0–1.
 */
class GuideSearch
{
    /** nomic-embed-text is trained with these task prefixes; leaving them out lowers match quality. */
    public const DOCUMENT_PREFIX = 'search_document: ';

    public const QUERY_PREFIX = 'search_query: ';

    /** @var Collection<int, HelpArticle>|null */
    private ?Collection $guides = null;

    public function __construct(private EmbeddingClient $embeddings) {}

    /**
     * The text a guide is embedded from: what it is called, where it is, and what it is about.
     */
    public static function documentText(HelpArticle $article): string
    {
        return self::DOCUMENT_PREFIX."{$article->title}. {$article->admin_page}. {$article->page_summary} Keywords: {$article->keywords}";
    }

    /**
     * @return list<array{article: HelpArticle, score: float}>
     */
    public function closest(string $question, int $limit = 3): array
    {
        $this->guides ??= HelpArticle::query()->whereNotNull('embedding')->get();

        if ($this->guides->isEmpty()) {
            return [];
        }

        [$query] = $this->embeddings->embed([self::QUERY_PREFIX.$question]);

        return $this->guides
            ->map(fn (HelpArticle $article) => ['article' => $article, 'score' => $this->cosine($query, $article->embedding)])
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        return $normA == 0.0 || $normB == 0.0 ? 0.0 : $dot / (sqrt($normA) * sqrt($normB));
    }
}
