<?php

namespace App\Enums;

/**
 * Who answers a question once its topic is known. Chosen in one place,
 * ChatOrchestrator::responderFor(), so the routing can be read top to bottom.
 */
enum Responder
{
    /** Facts from the store, fetched from Shopify by Laravel. No model writes them, so they can't be made up. */
    case ShopifyData;

    /** Instructions and explanations: meaning search finds guides, the LLM answers from them. */
    case RagLlm;

    /** Laya was unsure: the LLM answers from guides, or says the question needs store data (then ShopifyData). */
    case RagLlmOrShopifyData;

    /** Growth advice: the store's numbers plus growth guides, written up by the LLM. */
    case Advisor;

    /** Not about running the store. */
    case OutOfScope;

    /** A rule already knows the answer (private data is refused, the future can't be counted), so nothing is fetched or written by a model. */
    case FixedReply;
}
