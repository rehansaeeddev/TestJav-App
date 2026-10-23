<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageFeedbackController;
use App\Http\Controllers\ShopifyWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['shopify.session', 'throttle:chat'])->group(function () {
        Route::post('chat', ChatController::class)->name('chat');
        Route::get('conversations/latest', [ConversationController::class, 'latest'])->name('conversations.latest');
        Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
        Route::post('messages/{message}/feedback', MessageFeedbackController::class)->whereNumber('message')->name('messages.feedback');
    });

    Route::post('webhooks/app-uninstalled', [ShopifyWebhookController::class, 'appUninstalled'])
        ->middleware('shopify.webhook')
        ->name('webhooks.app-uninstalled');
});
