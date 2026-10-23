<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Asks a decision engine to pick one option for a piece of text, over Jev's
 * /v1/systemone protocol: local Laya for the prototype, OpenRouter's Jev later.
 * It returns probabilities, never free text.
 */
class JevClient
{
    /**
     * Pick the best option for the text.
     *
     * @param  array<string, string>  $options  label => description
     * @return array{choice: string, confidence: float|null, probabilities: array<string, float>, cost: float|null}
     */
    public function choose(string $text, string $instructions, array $options): array
    {
        $request = Http::acceptJson()->timeout(config('services.decisions.timeout'));

        if (filled($key = config('services.decisions.key'))) {
            $request = $request->withToken($key);
        }

        $response = $request->post(config('services.decisions.url'), array_filter([
            'model' => config('services.decisions.model'),
            'head_max_len' => filled($headMaxLen = config('services.decisions.head_max_len')) ? (int) $headMaxLen : null,
            'state' => ['message' => $text],
            'questions' => [
                'answer' => [
                    'type' => 'choice',
                    'instructions' => $instructions,
                    'criteria' => $options,
                ],
            ],
        ]));

        if ($response->failed()) {
            throw new RuntimeException('Decision engine request failed with HTTP '.$response->status());
        }

        $choice = $response->json('answers.answer.choice');

        if (! array_key_exists($choice, $options)) {
            throw new RuntimeException('Decision engine returned an unknown option.');
        }

        return [
            'choice' => $choice,
            'confidence' => $response->json('answers.answer.confidence'),
            'probabilities' => $response->json('answers.answer.probabilities', []),
            'cost' => $response->json('usage.cost'),
        ];
    }
}
