<?php

namespace App\Console\Commands;

use App\Enums\ChatIntent;
use App\Services\JevClient;
use Illuminate\Console\Command;
use Throwable;

class JevTest extends Command
{
    protected $signature = 'jev:test {message : The merchant message to classify}';

    protected $description = 'Send one merchant message to the decision engine and show which intent it picks';

    public function handle(JevClient $jev): int
    {
        try {
            $result = $jev->choose(
                $this->argument('message'),
                ChatIntent::QUESTION,
                ChatIntent::options(),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Intent: {$result['choice']} (confidence: ".($result['confidence'] ?? 'n/a').')');

        $this->table(
            ['Option', 'Probability'],
            collect($result['probabilities'])->map(fn ($p, $k) => [$k, round($p, 3)])->values(),
        );

        $this->line('Cost (USD): '.($result['cost'] ?? 'n/a'));

        return self::SUCCESS;
    }
}
