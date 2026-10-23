<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Http\Requests\ChatRequest;
use App\Http\Resources\ChatReplyResource;
use App\Models\Conversation;
use App\Models\Shop;
use App\Services\Chat\ChatOrchestrator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    /**
     * Send one merchant message and get the bot's reply. Without a conversation_id a new
     * conversation starts; with one, it must belong to this shop and staff member (else 404).
     *
     * With "Accept: text/event-stream" the reply is streamed as server-sent events:
     * "status" events naming each stage as it starts (understanding, fetching, searching, writing),
     * "delta" events with model-written text as it arrives, then one "done" event with the
     * full reply (same shape as the JSON response). Otherwise a single JSON response.
     */
    public function __invoke(ChatRequest $request, ChatOrchestrator $bot): ChatReplyResource|StreamedResponse
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shopify_shop');
        $staffUserId = (string) $request->attributes->get('shopify_user');

        $conversation = $request->filled('conversation_id')
            ? $shop->conversations()->where('staff_user_id', $staffUserId)->findOrFail($request->integer('conversation_id'))
            : $shop->conversations()->create(['staff_user_id' => $staffUserId]);

        $message = trim($request->string('message'));

        if (! str_contains((string) $request->header('Accept'), 'text/event-stream')) {
            $reply = $bot->handle($conversation, $message);

            return ChatReplyResource::make($reply)
                ->additional(['conversation_id' => $conversation->id, 'message_id' => $this->replyId($conversation)]);
        }

        return response()->stream(function () use ($bot, $conversation, $message, $request) {
            $send = function (array $event): void {
                echo 'data: '.json_encode($event)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            };

            try {
                $reply = $bot->handle($conversation, $message,
                    onDelta: fn (string $text) => $send(['type' => 'delta', 'text' => $text]),
                    onStatus: fn (string $stage) => $send(['type' => 'status', 'stage' => $stage]),
                );

                $send([
                    'type' => 'done',
                    'data' => ChatReplyResource::make($reply)->resolve($request),
                    'conversation_id' => $conversation->id,
                    'message_id' => $this->replyId($conversation),
                ]);
            } catch (Throwable $e) {
                report($e);

                $send(['type' => 'error', 'message' => 'The assistant hit a problem. Please try again.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The id of the reply just saved, so the merchant can rate it.
     */
    private function replyId(Conversation $conversation): ?int
    {
        return $conversation->messages()->where('role', MessageRole::Assistant)->latest('id')->value('id');
    }
}
