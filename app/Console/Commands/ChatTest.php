<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Shop;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Shopify\StoreData;
use Tests\Fakes\FakeStoreData;
use Illuminate\Console\Command;

class ChatTest extends Command
{
    protected $signature = 'chat:test
        {messages?* : Messages to send in order, in one conversation. Leave out to chat interactively}
        {--timezone=UTC : Timezone of the demo shop, e.g. Asia/Karachi}';

    protected $description = 'Chat with the merchant bot in the terminal, using a demo shop with sample orders';

    public function handle(): int
    {
        /** The demo shop has no Shopify token, so always use sample orders here. */
        $this->laravel->bind(StoreData::class, FakeStoreData::class);
        $bot = $this->laravel->make(ChatOrchestrator::class);

        $shop = Shop::firstOrCreate(['domain' => 'demo-shop.myshopify.com']);
        $shop->update(['timezone' => $this->option('timezone')]);

        $conversation = $shop->conversations()->create(['staff_user_id' => 'cli']);

        $messages = $this->argument('messages');

        if ($messages !== []) {
            foreach ($messages as $message) {
                $this->exchange($bot, $conversation, $message);
            }

            return self::SUCCESS;
        }

        $this->info('Chatting with the demo shop. Type "exit" to stop.');

        while (true) {
            $message = trim((string) $this->ask('You'));

            if (in_array(strtolower($message), ['', 'exit', 'quit'], true)) {
                return self::SUCCESS;
            }

            $this->exchange($bot, $conversation, $message);
        }
    }

    private function exchange(ChatOrchestrator $bot, Conversation $conversation, string $message): void
    {
        $this->newLine();
        $this->line("<fg=cyan>You:</> {$message}");

        $startedAt = microtime(true);
        $reply = $bot->handle($conversation, $message);
        $seconds = round(microtime(true) - $startedAt, 1);

        $intent = $conversation->messages()->latest('id')->first()?->intent?->value ?? 'follow-up';

        $this->line("<fg=green>Bot:</> {$reply->text}");

        if ($reply->adminPath !== null) {
            $this->line("     <fg=yellow>↳ {$reply->linkLabel}</> (admin path {$reply->adminPath})");
        }

        if ($reply->helpUrl !== null) {
            $this->line("     <fg=yellow>↳ Official guide:</> {$reply->helpUrl}");
        }

        $this->line("     <fg=gray>[{$intent}, {$seconds}s]</>");
    }
}
