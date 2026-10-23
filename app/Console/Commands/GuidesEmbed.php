<?php

namespace App\Console\Commands;

use App\Models\HelpArticle;
use App\Services\Chat\GuideSearch;
use App\Services\EmbeddingClient;
use Illuminate\Console\Command;

class GuidesEmbed extends Command
{
    protected $signature = 'guides:embed {--missing : Only guides that have no embedding yet}';

    protected $description = 'Store a meaning vector for every help guide, for meaning-based guide search';

    public function handle(EmbeddingClient $embeddings): int
    {
        $guides = HelpArticle::query()->when($this->option('missing'), fn ($query) => $query->whereNull('embedding'))->get();

        $guides->chunk(16)->each(function ($chunk) use ($embeddings) {
            $vectors = $embeddings->embed($chunk->map(fn (HelpArticle $article) => GuideSearch::documentText($article))->values()->all());

            $chunk->values()->each(fn (HelpArticle $article, int $i) => $article->forceFill(['embedding' => $vectors[$i]])->save());
        });

        $this->info("Embedded {$guides->count()} guides.");

        return self::SUCCESS;
    }
}
