<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Http\Requests\MessageFeedbackRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\Shop;

class MessageFeedbackController extends Controller
{
    /**
     * Save "good" or "wrong" on one of the bot's replies. Only replies in this staff member's
     * own conversations in this shop can be rated; anything else is "not found".
     */
    public function __invoke(MessageFeedbackRequest $request, int $message): MessageResource
    {
        /** @var Shop $shop */
        $shop = $request->attributes->get('shopify_shop');

        $reply = Message::query()
            ->where('role', MessageRole::Assistant)
            ->whereHas('conversation', fn ($query) => $query
                ->where('shop_id', $shop->id)
                ->where('staff_user_id', (string) $request->attributes->get('shopify_user')))
            ->findOrFail($message);

        $reply->update(['feedback' => $request->string('rating')->toString()]);

        return MessageResource::make($reply);
    }
}
