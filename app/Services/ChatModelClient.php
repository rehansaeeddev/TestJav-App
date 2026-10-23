<?php

namespace App\Services;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Writes the customer-facing reply through an OpenAI-compatible chat completions API
 * (local Ollama for the prototype, OpenRouter later).
 */
class ChatModelClient
{
    /**
     * Ask the chat model for a reply.
     *
     * @param  string  $instructions  Rules and store data the model must answer from.
     * @param  string  $customerMessage  The customer's message, sent as untrusted user content.
     */
    public function reply(string $instructions, string $customerMessage, int $maxTokens = 250): string
    {
        $model = config('services.chat.model');

        if (blank($model)) {
            throw new RuntimeException('CHAT_MODEL is not set.');
        }

        $request = Http::acceptJson()->timeout(config('services.chat.timeout'));

        if (filled($key = config('services.chat.key'))) {
            $request = $request->withToken($key);
        }

        $response = $request->post(config('services.chat.url'), array_filter([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $customerMessage],
            ],
            'temperature' => 0.2,
            'max_tokens' => $maxTokens,
            'stream' => false,
            'reasoning_effort' => config('services.chat.reasoning_effort'),
        ], fn ($value) => filled($value)));

        if ($response->failed()) {
            throw new RuntimeException('Chat model request failed with HTTP '.$response->status());
        }

        $content = (string) $response->json('choices.0.message.content', '');

        // Some small models wrap hidden reasoning in <think> tags; customers never see it.
        return trim(preg_replace('/<think>.*?<\/think>/s', '', $content));
    }

    /**
     * Same as reply(), but passes each piece of text to $onDelta as the model writes it
     * (OpenAI-compatible server-sent events). Returns the full text at the end.
     *
     * @param  callable(string): void  $onDelta
     */
    public function stream(string $instructions, string $customerMessage, callable $onDelta, int $maxTokens = 250): string
    {
        $model = config('services.chat.model');

        if (blank($model)) {
            throw new RuntimeException('CHAT_MODEL is not set.');
        }

        $request = Http::withOptions(['stream' => true])
            ->accept('text/event-stream')
            ->timeout(config('services.chat.timeout'));

        if (filled($key = config('services.chat.key'))) {
            $request = $request->withToken($key);
        }

        $response = $request->post(config('services.chat.url'), array_filter([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $customerMessage],
            ],
            'temperature' => 0.2,
            'max_tokens' => $maxTokens,
            'stream' => true,
            'reasoning_effort' => config('services.chat.reasoning_effort'),
        ], fn ($value) => filled($value)));

        if ($response->failed()) {
            throw new RuntimeException('Chat model request failed with HTTP '.$response->status());
        }

        $body = $response->toPsrResponse()->getBody();
        $text = '';

        while (! $body->eof()) {
            $line = trim(Utils::readLine($body));

            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, 5));

            if ($payload === '[DONE]') {
                break;
            }

            $delta = (string) (json_decode($payload, true)['choices'][0]['delta']['content'] ?? '');

            if ($delta !== '') {
                $text .= $delta;
                $onDelta($delta);
            }
        }

        return trim(preg_replace('/<think>.*?<\/think>/s', '', $text));
    }

    /**
     * Answer from numbered sources. The model must start with "SOURCE: <n>" (0 = none answers it);
     * that line is not shown to the merchant. How sure the model was of its pick is measured from
     * its own token probabilities, not asked of it: a model asked "how sure are you?" says 90% either way.
     *
     * @param  (callable(string): void)|null  $onDelta  Receives the answer text after the SOURCE line, as it is written.
     *                                                  "SOURCE: D" (when the instructions offer it) means the question needs the store's own data; source is then -1.
     * @return array{text: string, source: int|null, source_confidence: float|null}
     */
    public function answerWithSource(string $instructions, string $customerMessage, ?callable $onDelta = null): array
    {
        $model = config('services.chat.model');

        if (blank($model)) {
            throw new RuntimeException('CHAT_MODEL is not set.');
        }

        $streaming = $onDelta !== null;
        $request = Http::withOptions(['stream' => $streaming])
            ->accept($streaming ? 'text/event-stream' : 'application/json')
            ->timeout(config('services.chat.timeout'));

        if (filled($key = config('services.chat.key'))) {
            $request = $request->withToken($key);
        }

        $response = $request->post(config('services.chat.url'), array_filter([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $customerMessage],
            ],
            'temperature' => 0.2,
            'max_tokens' => 350,
            'stream' => $streaming,
            'logprobs' => true,
            'reasoning_effort' => config('services.chat.reasoning_effort'),
        ], fn ($value) => filled($value)));

        if ($response->failed()) {
            throw new RuntimeException('Chat model request failed with HTTP '.$response->status());
        }

        /** @var list<array{token: string, logprob: float}> $tokens */
        $tokens = [];
        $text = '';

        if (! $streaming) {
            $text = (string) $response->json('choices.0.message.content', '');
            $tokens = $response->json('choices.0.logprobs.content', []) ?? [];
        } else {
            $body = $response->toPsrResponse()->getBody();
            $headerDone = false;

            while (! $body->eof()) {
                $line = trim(Utils::readLine($body));

                if (! str_starts_with($line, 'data:') || ($payload = trim(substr($line, 5))) === '[DONE]') {
                    if (($payload ?? null) === '[DONE]') {
                        break;
                    }

                    continue;
                }

                $chunk = json_decode($payload, true);
                $delta = (string) ($chunk['choices'][0]['delta']['content'] ?? '');
                $tokens = [...$tokens, ...($chunk['choices'][0]['logprobs']['content'] ?? [])];
                $text .= $delta;

                /** Hold text back until the SOURCE line has ended, so the merchant never sees it. */
                if (! $headerDone && str_contains($text, "\n")) {
                    $headerDone = true;
                    $rest = ltrim(substr($text, strpos($text, "\n") + 1));
                    if ($rest !== '') {
                        $onDelta($rest);
                    }
                } elseif ($headerDone && $delta !== '') {
                    $onDelta($delta);
                }
            }
        }

        $text = trim(preg_replace('/<think>.*?<\/think>/s', '', $text));

        /** The whole first line is the header, even if the model hedges on it ("SOURCE: 4, or SOURCE: 1"): the first number counts. */
        if (! preg_match('/^\s*\**SOURCE\**\s*:?\s*\**\s*(\d+|D\b)[^\n]*(?:\n(.*))?$/is', $text, $match)) {
            return ['text' => $text, 'source' => null, 'source_confidence' => null];
        }

        return [
            'text' => trim($match[2] ?? ''),
            'source' => strtoupper($match[1]) === 'D' ? -1 : (int) $match[1],
            'source_confidence' => $this->sourceTokenConfidence($tokens, strtoupper($match[1])),
        ];
    }

    /**
     * Probability the model gave the source number it wrote: e^logprob of the first token holding that number.
     *
     * @param  list<array{token: string, logprob: float}>  $tokens
     */
    private function sourceTokenConfidence(array $tokens, string $number): ?float
    {
        foreach ($tokens as $token) {
            if (str_contains($token['token'] ?? '', $number) && isset($token['logprob'])) {
                return round(exp((float) $token['logprob']), 4);
            }
        }

        return null;
    }
}
