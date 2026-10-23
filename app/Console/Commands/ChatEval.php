<?php

namespace App\Console\Commands;

use App\Enums\ChatIntent;
use App\Enums\MessageRole;
use App\Models\IntentDecision;
use App\Models\Message;
use App\Models\Shop;
use App\Services\Chat\ChatOrchestrator;
use App\Services\ChatModelClient;
use App\Services\JevClient;
use App\Services\Shopify\StoreData;
use Tests\Fakes\FakeStoreData;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Replays the merchant question set through the real pipeline and real Laya, on the demo
 * shop's sample data, and scores each case: was it split right, routed right, and did the
 * answer target the right thing. Each case runs in a transaction that is rolled back.
 */
class ChatEval extends Command
{
    protected $signature = 'chat:eval
        {--filter= : Only run cases whose id contains this text}
        {--file=tests/Fixtures/merchant-questions.json : The question set}';

    protected $description = 'Score the chat routing against the merchant question set (uses real Laya, sample store data)';

    private const RULES_BEFORE_LAYA = ['small_talk', 'about_assistant', 'about_me', 'complaint', 'unclear_reference', 'private_data', 'future', 'off_topic', 'topic_follow_up', 'order_number', 'order_from_memory', 'products_from_memory', 'restock_advice', 'best_sellers', 'order_filter', 'growth_advice', 'revenue', 'legal_question', 'action_request', 'latest_order', 'order_list', 'product_list', 'named_page'];

    public function handle(JevClient $laya): int
    {
        /**
         * Work on a copy of the database: each case holds a write transaction while the LLM answers,
         * and on the live SQLite file that locks out the running app ("database is locked").
         */
        $copy = storage_path('app/evals/eval-database.sqlite');
        File::ensureDirectoryExists(dirname($copy));
        File::copy(config('database.connections.sqlite.database'), $copy);
        config(['database.connections.sqlite.database' => $copy]);
        DB::purge('sqlite');

        /** Sample data only, and the writer switched off: policy and follow-up texts are the fallback, not the model's. */
        $this->laravel->bind(StoreData::class, FakeStoreData::class);
        $this->laravel->instance(ChatModelClient::class, new class extends ChatModelClient
        {
            public function reply(string $instructions, string $customerMessage, int $maxTokens = 250): string
            {
                return '';
            }

            public function stream(string $instructions, string $customerMessage, callable $onDelta, int $maxTokens = 250): string
            {
                return '';
            }
        });

        $cases = collect(json_decode(File::get(base_path($this->option('file'))), true, flags: JSON_THROW_ON_ERROR)['cases'])
            ->when($this->option('filter'), fn (Collection $cases, string $filter) => $cases->filter(fn (array $case) => str_contains($case['id'], $filter)))
            ->values();

        $shop = Shop::firstOrCreate(['domain' => 'demo-shop.myshopify.com']);
        $shop->update(['timezone' => 'UTC']);

        $results = $cases->map(function (array $case) use ($shop, $laya) {
            $this->output->write('.');

            return $this->runCase($case, $shop, $laya);
        });

        $this->newLine(2);
        $this->report($results);
        $this->save($results);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function runCase(array $case, Shop $shop, JevClient $laya): array
    {
        DB::beginTransaction();

        try {
            $bot = $this->laravel->make(ChatOrchestrator::class);
            $conversation = $shop->conversations()->create(['staff_user_id' => 'eval']);

            $setupIntents = collect($case['setup'] ?? [])->map(function (string $message) use ($bot, $conversation) {
                $bot->handle($conversation, $message);

                return $this->decisionsFor($conversation->messages()->where('role', MessageRole::Merchant)->latest('id')->first())->first()?->final_intent?->name;
            })->all();

            $reply = $bot->handle($conversation, $case['message']);
            $decisions = $this->decisionsFor($conversation->messages()->where('role', MessageRole::Merchant)->latest('id')->first());
        } finally {
            DB::rollBack();
        }

        $expect = $case['expect'];
        $splitOk = $decisions->count() === count($expect);
        $parts = collect($expect)->map(function (array $expected, int $index) use ($decisions) {
            $decision = $decisions->get($index);
            $intentOk = $decision !== null && $this->intentMatches($decision->final_intent?->name, (array) $expected['intent']);
            $targetOk = ! isset($expected['target']) || ($decision !== null && $this->targetMatches($decision->target, (array) $expected['target']));

            return ['expected' => $expected, 'decision' => $decision, 'intent_ok' => $intentOk, 'target_ok' => $targetOk];
        });

        $intentOk = $splitOk && $parts->every(fn (array $part) => $part['intent_ok']);
        $targetOk = $intentOk && $parts->every(fn (array $part) => $part['target_ok']);
        $replyOk = ! isset($case['reply_matches']) || preg_match($case['reply_matches'], $reply->text) === 1;

        return [
            'id' => $case['id'],
            'group' => $case['group'],
            'category' => $case['category'],
            'known_gap' => $case['known_gap'] ?? false,
            'message' => $case['message'],
            'expected' => collect($expect)->map(fn (array $e) => implode('|', (array) $e['intent']).(isset($e['target']) ? ' → '.implode('|', (array) $e['target']) : ''))->all(),
            'setup_ok' => collect($case['setup_expect'] ?? [])->every(fn (string $intent, int $i) => ($setupIntents[$i] ?? null) === $intent),
            'split_ok' => $splitOk,
            'intent_ok' => $intentOk,
            'pass' => $targetOk && $replyOk,
            'blame' => match (true) {
                ! $targetOk => $this->blame($splitOk, $parts),
                ! $replyOk => 'reply_text',
                default => null,
            },
            'laya_alone' => count($expect) === 1 ? $this->layaAlone($laya, $case['message'], (array) $expect[0]['intent']) : null,
            'got' => $decisions->map(fn (IntentDecision $d) => [
                'question' => $d->question,
                'intent' => $d->final_intent?->name,
                'decided_by' => $d->decided_by,
                'target' => $d->target,
                'laya_choice' => $d->laya_choice?->name,
                'laya_confidence' => $d->laya_confidence,
                'laya_ms' => $d->laya_ms,
            ])->all(),
            'reply' => $reply->text,
        ];
    }

    /**
     * @return Collection<int, IntentDecision>
     */
    private function decisionsFor(?Message $message): Collection
    {
        return $message?->intentDecisions()->orderBy('part')->get() ?? collect();
    }

    /**
     * How-to and page questions are both answered by RAG + LLM from the same guides, so either label
     * is right for either; the target (which guide) is still checked separately.
     *
     * @param  list<string>  $expected
     */
    private function intentMatches(?string $intent, array $expected): bool
    {
        $guides = ['HowTo', 'ExplainPage'];

        return in_array($intent, $expected, true) || (in_array($intent, $guides, true) && array_intersect($expected, $guides) !== []);
    }

    /**
     * @param  list<string>  $acceptable
     */
    private function targetMatches(?string $target, array $acceptable): bool
    {
        return collect($acceptable)->contains(fn (string $wanted) => str_ends_with($wanted, '*')
            ? str_starts_with((string) $target, rtrim($wanted, '*'))
            : $target === $wanted);
    }

    /**
     * Which layer made the first wrong call.
     *
     * @param  Collection<int, array{expected: array<string, mixed>, decision: IntentDecision|null, intent_ok: bool, target_ok: bool}>  $parts
     */
    private function blame(bool $splitOk, Collection $parts): string
    {
        if (! $splitOk) {
            return 'split';
        }

        $wrong = $parts->first(fn (array $part) => ! $part['intent_ok']);

        if ($wrong === null) {
            return 'answer_target';
        }

        $steps = explode(' > ', $wrong['decision']->decided_by);

        return match (true) {
            in_array($steps[0], self::RULES_BEFORE_LAYA, true) => "rule_before_laya:{$steps[0]}",
            in_array($steps[0], ['guide_follow_up', 'follow_up_answer', 'laya_failed'], true) => $steps[0],
            count($steps) === 1 => 'laya',
            in_array($wrong['decision']->laya_choice?->name, (array) $wrong['expected']['intent'], true) => "rule_after_laya_broke_it:{$steps[1]}",
            default => 'laya_then_rule_missed',
        };
    }

    /**
     * Laya on the whole message with no rules, the number a JEV swap would be compared on.
     *
     * @param  list<string>  $expected
     * @return array{choice: string|null, confidence: float|null, ok: bool}
     */
    private function layaAlone(JevClient $laya, string $message, array $expected): array
    {
        try {
            $answer = $laya->choose($message, ChatIntent::QUESTION, ChatIntent::options());
            $choice = ChatIntent::from($answer['choice'])->name;

            return ['choice' => $choice, 'confidence' => $answer['confidence'], 'ok' => in_array($choice, $expected, true)];
        } catch (Throwable) {
            return ['choice' => null, 'confidence' => null, 'ok' => false];
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     */
    private function report(Collection $results): void
    {
        $scored = $results->reject(fn (array $r) => $r['known_gap']);
        $percent = fn (Collection $rows, string $key = 'pass') => $rows->isEmpty() ? '-' : round($rows->where($key, true)->count() / $rows->count() * 100).'% ('.$rows->where($key, true)->count().'/'.$rows->count().')';
        $layaRows = $scored->whereNotNull('laya_alone');

        $this->info('Overall (known gaps left out)');
        $this->table(['Measure', 'Score'], [
            ['Fully correct (topic + target)', $percent($scored)],
            ['Right topic', $percent($scored, 'intent_ok')],
            ['Seen wording (rules were tuned on it)', $percent($scored->where('group', 'seen'))],
            ['Fresh wording', $percent($scored->where('group', 'fresh'))],
            ['Written by the store owner', $percent($scored->where('group', 'owner'))],
            ['Blind check (owner, nothing tuned on it)', $percent($scored->where('group', 'blind'))],
            ['Legal questions', $percent($scored->where('group', 'legal'))],
            ['Laya alone, no rules (single-question cases)', $layaRows->isEmpty() ? '-' : round($layaRows->where('laya_alone.ok', true)->count() / $layaRows->count() * 100).'% ('.$layaRows->where('laya_alone.ok', true)->count().'/'.$layaRows->count().')'],
            ['Setup turns routed right (context cases)', $percent($scored->whereNotNull('setup_ok')->where('category', 'context'), 'setup_ok')],
            ['Known gaps that passed anyway', $results->where('known_gap', true)->where('pass', true)->count().' of '.$results->where('known_gap', true)->count()],
        ]);

        $this->info('By category');
        $this->table(['Category', 'Fully correct'], $scored->groupBy('category')->map(fn (Collection $rows, string $category) => [$category, $percent($rows)])->values()->all());

        $failures = $scored->where('pass', false);
        $this->info('Which layer made the wrong call');
        $this->table(['Layer', 'Cases'], $failures->countBy('blame')->sortDesc()->map(fn (int $count, string $blame) => [$blame, $count])->values()->all());

        $this->info('Failures');
        $this->table(['Case', 'Message', 'Expected', 'Got', 'Decided by', 'Blame'], $failures->map(fn (array $r) => [
            $r['id'],
            mb_strimwidth($r['message'], 0, 45, '…'),
            implode("\n", $r['expected']),
            collect($r['got'])->map(fn (array $g) => "{$g['intent']} → {$g['target']}")->implode("\n"),
            collect($r['got'])->pluck('decided_by')->implode("\n"),
            $r['blame'],
        ])->values()->all());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     */
    private function save(Collection $results): void
    {
        $path = storage_path('app/evals/chat-eval-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));

        File::put($path, json_encode([
            'ran_at' => now()->toIso8601String(),
            'laya_model' => config('services.decisions.model'),
            'labels_hash' => md5(ChatIntent::QUESTION.json_encode(ChatIntent::options())),
            'cases' => $results->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->line("Saved: {$path}");
    }
}
