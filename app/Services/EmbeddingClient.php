<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns text into meaning vectors through an OpenAI-compatible embeddings API (local Ollama).
 * Texts with similar meaning get vectors pointing the same way, even with no words in common.
 */
class EmbeddingClient
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        $response = Http::acceptJson()
            ->timeout(config('services.embeddings.timeout'))
            ->post(config('services.embeddings.url'), [
                'model' => config('services.embeddings.model'),
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Embedding request failed with HTTP '.$response->status());
        }

        $vectors = collect($response->json('data', []))->sortBy('index')->pluck('embedding')->values()->all();

        if (count($vectors) !== count($texts)) {
            throw new RuntimeException('Embedding response did not match the request.');
        }

        return $vectors;
    }
}
