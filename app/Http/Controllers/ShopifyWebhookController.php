<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyWebhookController extends Controller
{
    /**
     * app/uninstalled: delete the shop, its tokens and its conversations.
     */
    public function appUninstalled(Request $request): Response
    {
        Shop::where('domain', $request->header('X-Shopify-Shop-Domain'))->delete();

        return response()->noContent();
    }
}
