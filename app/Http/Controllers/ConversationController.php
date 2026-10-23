<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat history for the signed-in staff member of the signed-in shop. Every query is
 * scoped to both, so nobody can read another shop's or colleague's conversations.
 */
class ConversationController extends Controller
{
    /**
     * The staff member's most recent conversation with its messages, or null.
     */
    public function latest(Request $request): ConversationResource|JsonResponse
    {
        $conversation = $this->shop($request)->conversations()
            ->where('staff_user_id', $this->staffUserId($request))
            ->with(['messages' => fn ($query) => $query->orderBy('id')->limit(200)])
            ->latest('id')
            ->first();

        return $conversation === null
            ? response()->json(['data' => null])
            : ConversationResource::make($conversation);
    }

    /**
     * Start a new, empty conversation ("New chat").
     */
    public function store(Request $request): ConversationResource
    {
        $conversation = $this->shop($request)->conversations()->create([
            'staff_user_id' => $this->staffUserId($request),
        ]);

        return ConversationResource::make($conversation->load('messages'));
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shopify_shop');
    }

    private function staffUserId(Request $request): string
    {
        return (string) $request->attributes->get('shopify_user');
    }
}
