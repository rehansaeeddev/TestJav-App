<?php

namespace App\Services\Chat;

use App\Enums\AwaitingAnswer;
use App\Enums\ChatIntent;
use App\Enums\MessageRole;
use App\Enums\Responder;
use App\Models\Conversation;
use App\Models\HelpArticle;
use App\Models\Message;
use App\Services\ChatModelClient;
use App\Services\JevClient;
use App\Services\Shopify\OrderFilter;
use App\Services\Shopify\ProductFilter;
use App\Services\Shopify\ShopifyApiError;
use App\Services\Shopify\StoreData;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs one merchant message through the bot:
 * 1. if the bot asked a follow-up question, try to use this message as the answer;
 * 2. otherwise the decision engine picks the intent, and plain rules correct it when the
 *    message has an explicit order number or time period;
 * 3. the intent's handler fetches data (help articles or store data) and builds the reply.
 *
 * Numbers, stock, prices, statuses and links come from code. The chat model only rewrites
 * help articles and policy text.
 */
class ChatOrchestrator
{
    /** "Low on stock" means this many items or fewer. */
    private const LOW_STOCK_THRESHOLD = 5;

    /** How many orders an order list shows. */
    private const ORDER_LIST_SIZE = 10;

    /**
     * Meaning-search scores (cosine, 0–1). Below MIN no guide is close enough to answer from;
     * at STRONG an "off-topic" verdict from Laya is overruled because a guide clearly matches.
     * Measured on the guide set: unrelated questions score about 0.43, related ones 0.65–0.80.
     */
    private const SEARCH_MIN_SCORE = 0.55;

    private const SEARCH_STRONG_SCORE = 0.68;

    /**
     * Search is clearly sure of the guide when the keyword match and the closest meaning agree, or the
     * closest guide scores at least this and leads the next one by GUIDE_SURE_GAP. Measured on the main
     * test set (40 of 52 how-to questions, all right) and checked on the blind and legal sets (all right).
     */
    private const GUIDE_SURE_SCORE = 0.65;

    private const GUIDE_SURE_GAP = 0.05;

    /** Below this, Laya's pick is not trusted on its own: RAG + LLM decide unless the message clearly asks for store data. */
    private const LAYA_MIN_CONFIDENCE = 0.80;

    /** Starts every answer to a request to change the store: the app can only read. */
    public const CANT_CHANGE_STORE = "I can't make changes in your store from here, only explain how.";

    /** Guides about the law (sourced from Shopify's Help Center); their answers always get the disclaimer. */
    private const LEGAL_GUIDES = ['gdpr-compliance', 'customer-data-requests', 'customer-privacy', 'data-sharing-opt-out', 'tax-registration',
        'pci-compliance', 'restricted-items', 'marketing-consent', 'store-accessibility'];

    /** Ends every answer to a law or compliance question. */
    public const LEGAL_DISCLAIMER = "This is general information, not legal advice. For your country's rules, check with a lawyer.";

    private const PRODUCT_LIST_SIZE = 20;

    /** Products read for one filtered answer ("under $25"): enough for most stores, within Shopify's query cost. */
    private const PRODUCT_FILTER_SCAN = 40;

    private const DATE_EXAMPLES = 'You can say today, yesterday, this week, last week, last 7 days, this month, last month, '
        .'a month like "September", or "all time".';

    public function __construct(
        private JevClient $decisions,
        private ChatModelClient $writer,
        private MessageParser $parser,
        private StoreData $store,
        private GuideSearch $guideSearch,
    ) {}

    /** Receives the chat model's text as it is written, when the caller streams the reply. */
    private ?Closure $onDelta = null;

    /** Receives the stage being worked on (see STATUS_*), so the merchant sees progress while waiting. */
    private ?Closure $onStatus = null;

    public const STATUS_UNDERSTANDING = 'understanding';

    public const STATUS_FETCHING = 'fetching';

    public const STATUS_SEARCHING = 'searching';

    public const STATUS_WRITING = 'writing';

    /** Whether the current answer was already sent piece by piece through $onDelta. */
    private bool $streamed = false;

    /** The earlier question in the same message, when this one points back at it ("…and how do I restock them?"). */
    private ?string $referent = null;

    /** How the current question was routed, kept so a Shopify error after routing can still log it. */
    private ?RoutingDecision $decision = null;

    /**
     * @param  (Closure(string): void)|null  $onDelta  Called with each piece of model-written text.
     * @param  (Closure(string): void)|null  $onStatus  Called with each stage as it starts (STATUS_*).
     */
    public function handle(Conversation $conversation, string $text, ?Closure $onDelta = null, ?Closure $onStatus = null): BotReply
    {
        $this->onDelta = $onDelta;
        $this->onStatus = $onStatus;

        $previousIntent = $conversation->messages()->where('role', MessageRole::Assistant)->latest('id')->first()?->intent;

        $merchantMessage = $conversation->messages()->create(['role' => MessageRole::Merchant, 'content' => $text]);

        $replies = [];
        $intent = null;
        $previousQuestion = null;

        /** Each question in the message is answered in turn; the first may answer our last follow-up. */
        foreach ($this->parser->splitQuestions($this->parser->applyCorrection($text)) as $index => $question) {
            if ($index > 0) {
                $this->emit("\n\n");
            }

            $this->streamed = false;
            $this->decision = null;

            /** "Show out-of-stock products, and explain how to restock them": "them" is in the part before. */
            $this->referent = $index > 0 && preg_match('/\b(it|them|those|these|one|another)\b/i', $question) ? $previousQuestion : null;
            $previousQuestion = $question;

            try {
                [$decision, $reply] = ($index === 0 ? $this->resumeFollowUp($conversation, $question) : null)
                    ?? $this->answerNewQuestion($conversation, $question, $previousIntent);
            } catch (ShopifyApiError $e) {
                report($e);

                /** Keep how the question was routed; the answer failed afterwards, in Shopify. */
                $decision = $this->decision ?? RoutingDecision::byRule(null, 'unrouted');
                $decision = new RoutingDecision($decision->intent, [...$decision->steps, 'shopify_error'], $decision->layaChoice,
                    $decision->layaConfidence, $decision->layaProbabilities, $decision->layaMs, Str::limit($e->getMessage(), 250));
                $reply = $this->storeUnavailable($e);
            }

            $reply = $reply->labelled($this->answeredBy($decision, $reply), array_filter([
                'laya' => $decision->layaConfidence,
                'search' => $reply->scores['search'] ?? null,
                'llm' => $reply->scores['llm'] ?? null,
            ], fn ($value) => $value !== null));

            /** "Restock the hoodie by 50": the app is read-only, said by code so the model can't skip it. */
            if ($this->parser->asksToDoAction($question) && $reply->pipeline === BotReply::PIPELINE_RAG_LLM
                && ! str_starts_with($reply->text, self::CANT_CHANGE_STORE)) {
                $reply = $reply->withIntro(self::CANT_CHANGE_STORE);
            }

            /** Added by code, not the model, so a legal answer can never leave it out. */
            $fromLegalGuide = in_array(Str::after((string) $reply->target, 'guide:'), self::LEGAL_GUIDES, true);

            if (($this->parser->asksLegalQuestion($question) || $fromLegalGuide) && $reply->target !== 'out_of_scope') {
                $reply = $reply->withNote(self::LEGAL_DISCLAIMER);

                if ($this->streamed) {
                    $this->emit("\n\n".self::LEGAL_DISCLAIMER);
                }
            }

            $this->logDecision($merchantMessage, $index, $question, $decision, $reply);
            $this->remember($conversation, $reply);
            $questionIntent = $decision->intent;

            /** Answers that didn't come from the model are sent whole, so a streamed reply shows every part. */
            if (! $this->streamed) {
                $this->emit($reply->text);
            }

            $replies[] = $reply;
            $intent ??= $questionIntent;
            $previousIntent = $questionIntent;
        }

        $reply = count($replies) === 1 ? $replies[0] : new BotReply(
            collect($replies)->pluck('text')->implode("\n\n"),
            collect($replies)->firstWhere('adminPath', '!==', null)?->adminPath,
            collect($replies)->firstWhere('adminPath', '!==', null)?->linkLabel,
            collect($replies)->firstWhere('helpUrl', '!==', null)?->helpUrl,
            answeredBy: collect($replies)->pluck('answeredBy')->implode('  |  '),
            confidence: $replies[0]->confidence,
        );

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $reply->text,
            'intent' => $intent,
            'admin_path' => $reply->adminPath,
            'link_label' => $reply->linkLabel,
            'help_url' => $reply->helpUrl,
            'answered_by' => $reply->answeredBy,
            'confidence' => $reply->confidence === [] ? null : $reply->confidence,
        ]);

        return $reply;
    }

    /**
     * Use the message as the answer to the bot's last follow-up question, if it is one.
     * Anything else is treated as a new question.
     *
     * @return array{RoutingDecision, BotReply}|null
     */
    private function resumeFollowUp(Conversation $conversation, string $text): ?array
    {
        $awaiting = $conversation->awaiting;

        if ($awaiting === null) {
            return null;
        }

        $conversation->update(['awaiting' => null, 'pending_intent' => null]);

        switch ($awaiting) {
            case AwaitingAnswer::OrderNumber:
                $number = $this->parser->orderNumber($text) ?? $this->parser->anyNumber($text);

                return $number === null ? null : [RoutingDecision::byRule(ChatIntent::FindOrder, 'follow_up_answer'), $this->describeOrder($conversation, $number)];

            case AwaitingAnswer::DateRange:
                $range = $this->parser->dateRange($text, $this->timezone($conversation));

                if ($range !== null) {
                    return [RoutingDecision::byRule(ChatIntent::OrderStats, 'follow_up_answer'), $this->countOrders($conversation, $range)];
                }

                /** A short reply is an attempt at dates, not a new question: ask again instead of guessing. */
                return str_word_count($text) <= 4
                    ? [RoutingDecision::byRule(ChatIntent::OrderStats, 'follow_up_answer'), $this->askFollowUp($conversation, AwaitingAnswer::DateRange, ChatIntent::OrderStats,
                        "Sorry, I didn't catch the dates. ".self::DATE_EXAMPLES)]
                    : null;

            case AwaitingAnswer::PageName:
                $words = $this->parser->pageSubjectWords($text);
                $article = $words === null ? null : $this->pageNamedIn($words);

                return $article === null ? null : [RoutingDecision::byRule(ChatIntent::ExplainPage, 'follow_up_answer'), $this->explainPage($conversation, $article, fromMemory: false)];

            case AwaitingAnswer::ProductName:
                /** No match means the merchant moved on: answer it as a new question instead of asking again. */
                $terms = $this->parser->productSearchTerms($text);
                $products = $terms === null ? [] : $this->store->findProducts($conversation->shop, $terms);

                return $products === [] ? null : [RoutingDecision::byRule(ChatIntent::Products, 'follow_up_answer'), $this->foundProducts($terms, $products)];
        }

        return null;
    }

    /**
     * @return array{RoutingDecision, BotReply}
     */
    private function answerNewQuestion(Conversation $conversation, string $text, ?ChatIntent $previousIntent = null): array
    {
        if (($guide = $this->guideBeingDiscussed($conversation, $text, $previousIntent)) !== null) {
            $this->decision = RoutingDecision::byRule(ChatIntent::HowTo, 'guide_follow_up');

            return [$this->decision, $this->guideFollowUp($conversation, $guide, $text)];
        }

        $this->status(self::STATUS_UNDERSTANDING);
        $this->decision = $this->classify($conversation, $text, $previousIntent);

        $reply = match ($this->responderFor($conversation, $text)) {
            Responder::ShopifyData => $this->fromStoreData($conversation, $text),
            Responder::RagLlm => $this->decision->intent === ChatIntent::ExplainPage
                ? $this->namedOrCurrentPage($conversation, $text)
                : $this->howTo($conversation, $text),
            Responder::RagLlmOrShopifyData => $this->fromGuidesOrStoreData($conversation, $text),
            Responder::Advisor => $this->growthAdvice($conversation, $text),
            Responder::OutOfScope => $this->outOfScope(),
            Responder::FixedReply => $this->fixedReply($text),
        };

        return [$this->decision, $reply];
    }

    /**
     * Who answers, once the topic is known. The topic comes from a rule that is certain, or from Laya.
     *
     *  - Store facts (orders, products, policies, settings, discounts) → Shopify data. Policies are then
     *    summarised by the LLM; everything else is shown as fetched.
     *  - How-to and page questions → RAG + LLM.
     *  - Growth questions → the advisor (store numbers + RAG → LLM).
     *  - Laya under 80% sure, with nothing in the message clearly asking for store data → RAG + LLM decide,
     *    falling back to Shopify data when the LLM says the question needs it.
     *  - Laya says off-topic but a guide clearly matches in meaning → RAG + LLM.
     */
    private function responderFor(Conversation $conversation, string $text): Responder
    {
        $intent = $this->decision->intent;
        $layaAlone = $this->decision->steps === ['laya'];

        if (in_array($this->decision->decidedBy(), ['small_talk', 'about_assistant', 'about_me', 'complaint', 'private_data', 'future', 'unclear_reference'], true)) {
            return Responder::FixedReply;
        }

        if ($intent === ChatIntent::GrowthAdvice) {
            return Responder::Advisor;
        }

        if (in_array($intent, [ChatIntent::HowTo, ChatIntent::ExplainPage], true)) {
            return Responder::RagLlm;
        }

        if ($intent === ChatIntent::Other) {
            $signalled = $layaAlone && $this->parser->looksLikeQuestion($text) && ! $this->parser->isHowToQuestion($text)
                ? $this->intentFromSignals($conversation, $text)
                : null;

            /** "Can I email customers…" mentions an email, not the store's own settings. */
            if ($signalled === ChatIntent::StoreDetails && ! preg_match('/\b(my|our)\s+(store|shop)|\bmy\b/i', $text)) {
                $signalled = null;
            }

            /** "What timezone is my store set to?": the question names store data Laya missed. */
            if ($signalled !== null) {
                $this->decision = $this->decision->overruledBy($signalled, 'data_signal');

                return Responder::ShopifyData;
            }

            if ($layaAlone && $this->strongGuideMatch($text)) {
                $this->decision = $this->decision->overruledBy(ChatIntent::HowTo, 'strong_guide_match');

                return Responder::RagLlm;
            }

            return Responder::OutOfScope;
        }

        if ($layaAlone && ($this->decision->layaConfidence ?? 1.0) < self::LAYA_MIN_CONFIDENCE) {
            /** Laya unsure: a clue in the question itself ("beanie stock?", "what email do customers see") beats its guess. */
            $signalled = $this->intentFromSignals($conversation, $text);

            if ($signalled !== null && $signalled !== $intent) {
                $this->decision = $this->decision->overruledBy($signalled, 'unsure_laya_signal');

                return Responder::ShopifyData;
            }

            /** The clue agrees with Laya ("beanie stock?") unless the message names a task ("hide a product without deleting it"). */
            if (($signalled === null || $this->parser->namesTask($text)) && ! $this->hasDataSignal($conversation, $text)) {
                return Responder::RagLlmOrShopifyData;
            }
        }

        return Responder::ShopifyData;
    }

    /**
     * The store-data answer for the topic: order lookups and counts, products, policies, settings, discounts.
     */
    private function fromStoreData(Conversation $conversation, string $text): BotReply
    {
        $this->status(self::STATUS_FETCHING);

        return match ($this->decision->intent) {
            ChatIntent::FindOrder => $this->findOrder($conversation, $text),
            ChatIntent::OrderStats => $this->orderStats($conversation, $text),
            ChatIntent::Products => $this->products($conversation, $text),
            ChatIntent::Policies => $this->policy($conversation, $text),
            ChatIntent::StoreDetails => $this->storeDetails($conversation),
            ChatIntent::DiscountsAndCollections => $this->parser->asksAboutCollections($text)
                ? $this->collections($conversation)
                : $this->discounts($conversation),
            default => $this->outOfScope(),
        };
    }

    /**
     * Laya was unsure: the LLM picks a guide and answers, or says the question needs store data.
     */
    private function fromGuidesOrStoreData(Conversation $conversation, string $text): BotReply
    {
        /** A task ("hide a product without deleting it") is never a store-data lookup, so don't offer the model that way out. */
        $reply = $this->answerFromGuides($conversation, $text, offerStoreData: ! $this->parser->namesTask($text));

        if ($reply !== null) {
            $this->decision = $this->decision->overruledBy(ChatIntent::HowTo, 'laya_unsure_rag_llm');

            return $reply;
        }

        $this->decision = $this->decision->overruledBy($this->intentFromSignals($conversation, $text) ?? $this->decision->intent, 'laya_unsure_llm_says_store_data');

        return $this->fromStoreData($conversation, $text);
    }

    /**
     * Rules that are sure of the answer run first; otherwise Laya decides and the rules
     * after it correct known mistakes. Every step is recorded in the returned decision.
     */
    private function classify(Conversation $conversation, string $text, ?ChatIntent $previousIntent = null): RoutingDecision
    {
        if ($this->parser->isSmallTalk($text)) {
            return RoutingDecision::byRule(ChatIntent::Other, 'small_talk');
        }

        if ($this->parser->asksAboutAssistant($text)) {
            return RoutingDecision::byRule(ChatIntent::Other, 'about_assistant');
        }

        if ($this->parser->asksAboutMe($text)) {
            return RoutingDecision::byRule(ChatIntent::Other, 'about_me');
        }

        /** "Why can't you tell me the right answer?": apologise and ask again, not the introduction. */
        if ($this->parser->complainsAboutAssistant($text)) {
            return RoutingDecision::byRule(ChatIntent::Other, 'complaint');
        }

        /** "How do I change this setting?" with no guide shown yet: the app can't see the screen, so ask which one. */
        if (preg_match('/\b(this|that)\s+(setting|option|button|field|toggle|feature)\b/i', $text) && $conversation->lastHelpArticle()->doesntExist()) {
            return RoutingDecision::byRule(ChatIntent::HowTo, 'unclear_reference');
        }

        /** Passwords, card numbers, API keys, every customer's address: refused by a fixed reply, never by the model. */
        if ($this->parser->asksForPrivateData($text)) {
            return RoutingDecision::byRule(ChatIntent::Other, 'private_data');
        }

        /** "How many orders tomorrow?": the future can't be counted, so say so instead of asking for dates. */
        if ($this->parser->asksAboutFuture($text) && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::OrderStats, 'future');
        }

        if ($this->parser->isOffTopic($text)) {
            return RoutingDecision::byRule(ChatIntent::Other, 'off_topic');
        }

        $memory = $conversation->context ?? [];

        /** "Is it paid?" / "has that order shipped?" right after an order was shown: that order. */
        if (isset($memory['order']) && $this->parser->orderNumber($text) === null && $this->parser->pointsAtOrder($text)
            && $this->parser->asksOrderStatus($text) && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::FindOrder, 'order_from_memory');
        }

        /** "What about their prices?" right after products were listed: those products. */
        if (! empty($memory['products']) && $this->parser->pointsAtProducts($text) && $this->parser->mentionsProducts($text)
            && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::Products, 'products_from_memory');
        }

        /**
         * "And my timezone?", "what about the day before?", "exclude cancelled ones": a short follow-up stays on
         * the store-data topic just answered, unless it names a different one itself.
         */
        $dataTopics = [ChatIntent::FindOrder, ChatIntent::OrderStats, ChatIntent::Products, ChatIntent::Policies, ChatIntent::StoreDetails, ChatIntent::DiscountsAndCollections];

        if (in_array($previousIntent, $dataTopics, true) && $this->parser->continuesTopic($text) && ! $this->parser->isHowToQuestion($text)) {
            $signalled = $this->intentFromSignals($conversation, $text);

            /** "What about their names?" after products is still products; "and my timezone?" names a store detail. */
            if ($signalled === ChatIntent::StoreDetails && ! preg_match('/\bmy\b/i', $text)) {
                $signalled = null;
            }

            return RoutingDecision::byRule($signalled ?? $previousIntent, 'topic_follow_up');
        }

        /** "#1057", "order 1056", "did 1056 ship yet": an order number is about that order (unless asking how to do something or for a period). */
        $namesOrder = $this->parser->orderNumber($text) !== null
            || ($this->parser->anyNumber($text) !== null && $this->parser->asksOrderStatus($text));

        if ($namesOrder && ! $this->parser->isHowToQuestion($text) && ! $this->parser->asksToDoAction($text)
            && $this->parser->dateRange($text, $this->timezone($conversation)) === null) {
            return RoutingDecision::byRule(ChatIntent::FindOrder, 'order_number');
        }

        /** "Which product should I buy for my inventory?": best sellers against the stock they have left. */
        if ($this->parser->asksWhatToRestock($text) && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::Products, 'restock_advice');
        }

        /** "What are my best sellers?": counted from real orders (checked before growth advice, which also says "sell"). */
        if ($this->parser->asksForBestSellers($text) && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::Products, 'best_sellers');
        }

        /** "Do I need a cookie banner for GDPR?": law and compliance are answered only from guides. */
        if ($this->parser->asksLegalQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::HowTo, 'legal_question');
        }

        /** "How do I increase my sales?": advice from the store's own numbers, not a single guide. */
        if ($this->parser->asksForGrowthAdvice($text)) {
            return RoutingDecision::byRule(ChatIntent::GrowthAdvice, 'growth_advice');
        }

        /** "How much did I earn this year?": a money total from real orders, not an order count. */
        if ($this->parser->asksForRevenue($text) && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::OrderStats, 'revenue');
        }

        /** "Delete all my orders", "create a new order": the app can't change the store, so explain how. */
        if ($this->parser->asksToDoAction($text)) {
            return RoutingDecision::byRule(ChatIntent::HowTo, 'action_request');
        }

        /** "Unpaid orders over $100", "my biggest orders", "orders that used WELCOME10": filtered orders, not one order. */
        if (! $this->parser->orderFilter($text)->isEmpty() && preg_match('/\borders?\b/i', $text)
            && $this->parser->orderNumber($text) === null && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::OrderStats, 'order_filter');
        }

        /** "Show me the last order placed" wants one order, not the list. */
        if ($this->parser->asksForLatestOrder($text) && $this->parser->orderNumber($text) === null && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::FindOrder, 'latest_order');
        }

        if ($this->parser->asksForOrderList($text) && $this->parser->orderNumber($text) === null && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::OrderStats, 'order_list');
        }

        if ($this->parser->asksForProductList($text) && ! $this->parser->isHowToQuestion($text)) {
            return RoutingDecision::byRule(ChatIntent::Products, 'product_list');
        }

        /** "What is finance?" names an admin page we have a guide for; "what is my plan" asks for the store's own plan. */
        $asksOwnDetail = preg_match('/\bmy\b/i', $text) && $this->parser->mentionsStoreDetail($text);

        if (! $asksOwnDetail && ($subject = $this->parser->pageQuestionSubject($text)) !== null && HelpArticle::forPageNamed($subject) !== null) {
            return RoutingDecision::byRule(ChatIntent::ExplainPage, 'named_page');
        }

        $laya = ['choice' => null, 'confidence' => null, 'probabilities' => [], 'ms' => null, 'error' => null];
        $startedAt = hrtime(true);

        try {
            $answer = $this->decisions->choose($text, ChatIntent::QUESTION, ChatIntent::options());
            $intent = ChatIntent::from($answer['choice']);
            $laya = ['choice' => $intent, 'confidence' => $answer['confidence'], 'probabilities' => $answer['probabilities'], 'ms' => null, 'error' => null];
            $steps = ['laya'];
        } catch (Throwable $e) {
            report($e);

            /** Laya down or slow: the message's own clues (an order number, a period, a setting) decide, then the guides. */
            $intent = $this->intentFromSignals($conversation, $text)
                ?? (HelpArticle::confidentMatch($text) !== null || $this->parser->looksLikeQuestion($text) ? ChatIntent::HowTo : ChatIntent::Other);
            $laya['error'] = Str::limit($e->getMessage(), 250);
            $steps = ['laya_failed'];
        }

        $laya['ms'] = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        /** By reference: rules below add to $steps after this is defined. */
        $decision = function (ChatIntent $final, ?string $rule = null) use (&$steps, $laya): RoutingDecision {
            return new RoutingDecision(
                $final, $rule === null ? $steps : [...$steps, $rule],
                $laya['choice'], $laya['confidence'], $laya['probabilities'], $laya['ms'], $laya['error'],
            );
        };

        $storeDataIntents = [ChatIntent::Products, ChatIntent::Policies, ChatIntent::StoreDetails, ChatIntent::DiscountsAndCollections];

        /**
         * "How do I create a discount?" or "How do I archive an order?" asks for instructions, not store data.
         * An order question only counts when it names no order and no period ("how many orders today" stays data).
         */
        $asksWhereToSee = (bool) preg_match('/^[\s\p{P}]*how\s+(do|can|could|would|should)\s+(i|we)\s+(see|find|view|check|look\s+up|filter|export)\b/i', $text)
            || (bool) preg_match('/\b(export|download|excel|csv|spreadsheet|google\s+sheets?)\b/i', $text);
        $namesOrderData = $this->parser->orderNumber($text) !== null
            || ($this->parser->dateRange($text, $this->timezone($conversation)) !== null && ! $asksWhereToSee);
        $overridable = [...$storeDataIntents, ChatIntent::Other, ...($namesOrderData ? [] : [ChatIntent::FindOrder, ChatIntent::OrderStats])];

        if (in_array($intent, $overridable, true) && ($this->parser->isHowToQuestion($text) || $this->parser->asksWhatToDo($text))) {
            return $decision(ChatIntent::HowTo, 'how_to_phrasing');
        }

        /** A "policy" answer for a message that names no policy is really about the store itself. */
        if ($intent === ChatIntent::Policies && ! $this->parser->mentionsPolicy($text)) {
            $intent = ChatIntent::StoreDetails;
            $steps[] = 'no_policy_word';
        }

        /**
         * Store details only when the message asks for something they contain, never as a catch-all.
         * A rejected topic falls to what the message clearly names (a period, a policy, a code), then a guide, then off-topic.
         */
        if ($intent === ChatIntent::StoreDetails && ! $this->parser->mentionsStoreDetail($text)) {
            return $decision($this->intentFromSignals($conversation, $text) ?? (HelpArticle::confidentMatch($text) !== null ? ChatIntent::HowTo : ChatIntent::Other), 'no_store_detail_word');
        }

        if ($intent === ChatIntent::DiscountsAndCollections && ! $this->parser->mentionsDiscountsOrCollections($text)) {
            return $decision($this->intentFromSignals($conversation, $text) ?? (HelpArticle::confidentMatch($text) !== null ? ChatIntent::HowTo : ChatIntent::Other), 'no_discount_word');
        }

        /** "Which collections have products in them?" is about collections, not one product. */
        if ($intent === ChatIntent::Products && preg_match('/\bcollections?\b/i', $text)) {
            return $decision(ChatIntent::DiscountsAndCollections, 'collection_word');
        }

        if (! in_array($intent, [ChatIntent::FindOrder, ChatIntent::OrderStats], true)) {
            return $decision($intent);
        }

        /** "What's the price of a product called Ultra Dragon 9000?": no order word, but a product one. */
        if (! preg_match('/\b(orders?|#\d+)\b|#/i', $text) && $this->parser->mentionsProducts($text)) {
            return $decision(ChatIntent::Products, 'product_not_order');
        }

        $mentionsOrder = $this->parser->orderNumber($text) !== null || $this->parser->asksForLatestOrder($text);
        $mentionsPeriod = $this->parser->dateRange($text, $this->timezone($conversation)) !== null;

        return match (true) {
            $mentionsOrder && ! $mentionsPeriod => $decision(ChatIntent::FindOrder, $intent === ChatIntent::FindOrder ? null : 'order_number'),
            $mentionsPeriod && ! $mentionsOrder => $decision(ChatIntent::OrderStats, $intent === ChatIntent::OrderStats ? null : 'period'),
            default => $decision($intent),
        };
    }

    private function howTo(Conversation $conversation, string $text): BotReply
    {
        return $this->answerFromGuides($conversation, $text);
    }

    /**
     * The topic a message clearly names on its own, if any: "and today?" → orders, "and shipping?" → policy,
     * "is WELCOME10 still working" → discounts. Used when Laya's topic fails a sanity check.
     */
    private function intentFromSignals(Conversation $conversation, string $text): ?ChatIntent
    {
        return match (true) {
            $this->parser->orderNumber($text) !== null => ChatIntent::FindOrder,
            $this->parser->dateRange($text, $this->timezone($conversation)) !== null => ChatIntent::OrderStats,
            $this->parser->mentionsDiscountsOrCollections($text) => ChatIntent::DiscountsAndCollections,
            $this->parser->policyType($text) !== null => ChatIntent::Policies,
            /** "My store" alone is not a store detail: "is the leather belt live on my store" is about a product. */
            $this->parser->mentionsStoreDetail((string) preg_replace('/\bmy\s+(store|shop)\b/i', '', $text)) => ChatIntent::StoreDetails,
            $this->parser->mentionsProducts($text) => ChatIntent::Products,
            default => null,
        };
    }

    /**
     * Something in the message certainly asks for the store's own data, so Laya's data topic stands
     * even when Laya is unsure: an order number, a period, stock, a list, a policy, a code, or a
     * reference to the order or products just shown.
     */
    private function hasDataSignal(Conversation $conversation, string $text): bool
    {
        $memory = $conversation->context ?? [];

        return $this->parser->orderNumber($text) !== null
            || $this->parser->anyNumber($text) !== null
            || $this->parser->dateRange($text, $this->timezone($conversation)) !== null
            || $this->parser->asksForLowStock($text)
            || $this->parser->asksForBestSellers($text)
            || $this->parser->asksForProductCount($text)
            || $this->parser->asksForProductList($text)
            || $this->parser->asksForOrderList($text)
            || $this->parser->asksForLatestOrder($text)
            || $this->parser->mentionsPolicy($text)
            || $this->parser->mentionsDiscountsOrCollections($text)
            || (preg_match('/\bmy\b/i', $text) && $this->parser->mentionsStoreDetail($text))
            || (isset($memory['order']) && $this->parser->pointsAtOrder($text))
            || (! empty($memory['products']) && $this->parser->pointsAtProducts($text));
    }

    /**
     * Growth advice decided by rules from the store's own numbers, so it never invents a fact or names the
     * wrong feature, and reads the same every time. Each tip shows the number behind it and the admin page
     * from the matching guide. The most urgent four are shown.
     */
    private function growthAdvice(Conversation $conversation, string $text): BotReply
    {
        $this->status(self::STATUS_FETCHING);
        $facts = $this->storeFacts($conversation);

        $tips = collect($this->growthTips($facts))->take(4)->values()
            ->map(fn (array $tip, int $i) => ($i + 1).". **{$tip['title']}**: {$tip['why']} Where: {$tip['where']}.");

        $overview = HelpArticle::query()->where('slug', 'growth-marketing-performance')->first();

        return new BotReply("Here's what I'd do first, based on your store's last 30 days:\n".$tips->implode("\n")
            ."\nAsk me how to do any of these and I'll walk you through it.",
            '/analytics', 'Open Analytics', $overview?->source_url, target: 'growth_advice');
    }

    /**
     * The numbers growth and restock advice are built from, last 30 days unless stated.
     *
     * @return array{orders: int, orders_before: int, typical_order: float|null, currency: string, restock: list<array{title: string, sold: int, left: int, days_left: int}>, sold_out: list<string>, draft: int, discounts: int, missing_policies: list<string>, top_seller: array{title: string, quantity: int}|null}
     */
    private function storeFacts(Conversation $conversation): array
    {
        $shop = $conversation->shop;
        $now = now($this->timezone($conversation));
        $recent = collect($this->store->recentOrders($shop, $now->copy()->subDays(30), $now, 100));
        $restock = collect($this->restockRows($conversation));
        $policies = collect($this->store->policies($shop))->pluck('type');

        return [
            'orders' => $this->store->countOrders($shop, $now->copy()->subDays(30), $now),
            'orders_before' => $this->store->countOrders($shop, $now->copy()->subDays(60), $now->copy()->subDays(30)),
            /** The middle order value: one very large order doesn't move it, unlike the average. */
            'typical_order' => $recent->isEmpty() ? null : (float) $recent->map(fn (array $order) => (float) $order['total'])->median(),
            'currency' => (string) ($recent->first()['currency'] ?? ''),
            'restock' => $restock->filter(fn (array $row) => $row['days_left'] !== null && $row['days_left'] < 30)->values()->all(),
            'sold_out' => collect($this->store->lowestStockVariants($shop))->where('quantity', '<=', 0)
                ->map(fn (array $variant) => "{$variant['product_title']} ({$variant['variant_title']})")->values()->all(),
            'draft' => $this->store->countProducts($shop, 'DRAFT'),
            'discounts' => count($this->store->activeDiscounts($shop)),
            'missing_policies' => collect(['REFUND_POLICY' => 'refund', 'SHIPPING_POLICY' => 'shipping'])
                ->reject(fn (string $name, string $type) => $policies->contains($type))->values()->all(),
            'top_seller' => $restock->isEmpty() ? null : ['title' => $restock->first()['title'], 'quantity' => $restock->first()['sold']],
        ];
    }

    /**
     * Each rule fires only when its number says so, most urgent first: running out of what sells,
     * falling orders, missing policies, sold-out sizes, drafts, discounts, traffic, then always-useful tips.
     *
     * @param  array<string, mixed>  $facts  From storeFacts().
     * @return list<array{title: string, why: string, where: string}>
     */
    private function growthTips(array $facts): array
    {
        $where = fn (string $slug) => HelpArticle::query()->where('slug', $slug)->value('admin_page') ?? 'your Shopify admin';
        $money = fn (float $amount) => number_format($amount, 2).($facts['currency'] !== '' ? " {$facts['currency']}" : '');
        $tips = [];

        foreach (array_slice($facts['restock'], 0, 2) as $row) {
            $tips[] = ['title' => "Restock {$row['title']}", 'why' => "it sold {$row['sold']} in the last 30 days and only {$row['left']} are left (about {$row['days_left']} days).", 'where' => $where('adjust-inventory')];
        }

        if ($facts['orders_before'] > 0 && $facts['orders'] <= $facts['orders_before'] * 0.8) {
            $drop = (int) round(($facts['orders_before'] - $facts['orders']) / $facts['orders_before'] * 100);
            $tips[] = ['title' => 'Win shoppers back with an email campaign', 'why' => "orders fell {$drop}%, from {$facts['orders_before']} to {$facts['orders']}, compared with the 30 days before.", 'where' => $where('email-campaign-shopify-messaging')];
        }

        if ($facts['missing_policies'] !== []) {
            $tips[] = ['title' => 'Add your '.implode(' and ', $facts['missing_policies']).' '.(count($facts['missing_policies']) === 1 ? 'policy' : 'policies'), 'why' => 'buyers check these before they pay, and your store has none yet.', 'where' => $where('store-policies')];
        }

        if ($facts['sold_out'] !== []) {
            $tips[] = ['title' => 'Restock or hide sold-out sizes', 'why' => count($facts['sold_out']).' tracked '.(count($facts['sold_out']) === 1 ? 'variant is' : 'variants are').' at 0, e.g. '.implode(', ', array_slice($facts['sold_out'], 0, 2)).'.', 'where' => $where('adjust-inventory')];
        }

        if ($facts['draft'] > 0) {
            $tips[] = ['title' => "Publish your {$facts['draft']} draft ".($facts['draft'] === 1 ? 'product' : 'products'), 'why' => "shoppers can't buy them until they're active.", 'where' => $where('product-status')];
        }

        if ($facts['discounts'] === 0) {
            $tips[] = ['title' => 'Offer a welcome discount', 'why' => 'you have no active discounts, and a first-order code turns new visitors into buyers.', 'where' => $where('create-discount')];
        } elseif ($facts['typical_order'] !== null) {
            $tips[] = ['title' => 'Add free shipping just above your typical order', 'why' => 'half your orders are under '.$money($facts['typical_order']).'; free shipping over about '.$money(ceil($facts['typical_order'] * 1.25 / 5) * 5).' nudges shoppers to add one more item.', 'where' => $where('create-discount')];
        }

        if ($facts['orders'] < 10) {
            $tips[] = ['title' => 'Bring in more shoppers with Campaign Autopilot', 'why' => "{$facts['orders']} orders in 30 days is low, and Autopilot suggests ad campaigns and email automations for you.", 'where' => $where('campaign-autopilot')];
        }

        $tips[] = ['title' => 'Recover abandoned checkouts', 'why' => 'Shopify can email shoppers who left at checkout, so some of them come back and pay.', 'where' => $where('abandoned-checkouts')];

        if ($facts['top_seller'] !== null) {
            $tips[] = ['title' => "Feature {$facts['top_seller']['title']} on your home page", 'why' => "it's your best seller ({$facts['top_seller']['quantity']} sold in 30 days).", 'where' => $where('theme-editor')];
        }

        return $tips;
    }

    /**
     * What to reorder: the best sellers of the last 30 days, with stock left and how many days it lasts at
     * the current pace. "days_left" is null when the product doesn't track stock.
     *
     * @return list<array{title: string, sold: int, left: int|null, days_left: int|null}>
     */
    private function restockRows(Conversation $conversation): array
    {
        $shop = $conversation->shop;
        $now = now($this->timezone($conversation));

        return collect($this->store->bestSellers($shop, $now->copy()->subDays(30), $now, 5))
            ->map(function (array $seller) use ($shop) {
                $product = collect($this->store->findProducts($shop, $seller['title'], 3))
                    ->first(fn (array $product) => mb_strtolower($product['title']) === mb_strtolower($seller['title']));
                $left = $product !== null && $product['tracks_inventory'] ? max(0, (int) $product['total_inventory']) : null;

                return [
                    'title' => $seller['title'],
                    'sold' => (int) $seller['quantity'],
                    'left' => $left,
                    'days_left' => $left === null || $seller['quantity'] <= 0 ? null : (int) floor($left / ($seller['quantity'] / 30)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Which products should I buy for my inventory?": best sellers ranked by how soon they run out.
     */
    private function restockAdvice(Conversation $conversation): BotReply
    {
        $rows = collect($this->restockRows($conversation));

        if ($rows->isEmpty()) {
            return new BotReply("Nothing sold in the last 30 days, so there's no sales pace to base a reorder on. "
                .'Ask me "which products are low on stock?" to see what is running out.', '/products/inventory', 'Open Inventory', target: 'restock');
        }

        $tracked = $rows->whereNotNull('days_left')->sortBy('days_left')->values();
        $lines = $tracked->map(fn (array $row) => "• {$row['title']}: {$row['sold']} sold, {$row['left']} left, about {$row['days_left']} days of stock"
            .($row['days_left'] < 30 ? ' → reorder now' : ''));
        $untracked = $rows->whereNull('days_left')->pluck('title');

        $opening = $tracked->contains(fn (array $row) => $row['days_left'] < 30)
            ? 'Your best sellers of the last 30 days, the ones running out first on top:'
            : 'Nothing needs reordering yet: at the current pace your best sellers have more than a month of stock.';

        return new BotReply($opening."\n"
            .$lines->implode("\n")
            .($untracked->isNotEmpty() ? "\nThese sell but don't track stock, so I can't tell how much is left: ".$untracked->implode(', ').'.' : ''),
            '/products/inventory', 'Open Inventory', target: 'restock');
    }

    private function strongGuideMatch(string $text): bool
    {
        try {
            return ($this->guideSearch->closest($text, 1)[0]['score'] ?? 0) >= self::SEARCH_STRONG_SCORE;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * RAG + LLM: find the guides closest in meaning (plus the best keyword match), then the LLM
     * picks the one that answers the question and answers only from it, or says none does.
     * $current is the guide already being discussed; it is always offered first. $explain adds each
     * guide's summary, for "what is this page/feature" questions. $offerStoreData lets the model say the
     * question needs the store's own data instead (returns null: use the data handlers).
     */
    private function answerFromGuides(Conversation $conversation, string $text, ?HelpArticle $current = null, bool $explain = false, bool $offerStoreData = false): ?BotReply
    {
        $this->status(self::STATUS_SEARCHING);

        /** The earlier question in the same message says what "them" or "another one" means. */
        if ($this->referent !== null) {
            $text = "{$text} (about: {$this->referent})";
        }

        try {
            $found = collect($this->guideSearch->closest($text, 3));
        } catch (Throwable $e) {
            report($e);
            $found = collect();
        }

        $searchScores = $found->mapWithKeys(fn (array $hit) => [$hit['article']->id => $hit['score']]);
        $keyword = HelpArticle::confidentMatch($text);
        /** Three at most: every guide in the prompt adds about two seconds before the model's first word. */
        /** The keyword match goes first: when it exists it is precise, and only three guides fit in the prompt. */
        $guides = collect([$current, $keyword, ...$found->pluck('article')])->filter()->unique('id')->take(3)->values();
        $bestScore = $searchScores->max();

        /** Nothing close in meaning or by keyword: don't ask the model to stretch a guide to fit. */
        if ($guides->isEmpty() || ($current === null && $keyword === null && ($bestScore ?? 0) < self::SEARCH_MIN_SCORE)) {
            return $offerStoreData ? null : $this->notSureWhichGuide($text, $bestScore);
        }

        /** Search is clearly sure: the model only writes from that one guide, so it can't pick a wrong one, and reads less. */
        $sure = $current === null && ! $offerStoreData ? $this->guideSearchIsSure($keyword, $found) : null;

        if ($sure !== null) {
            return $this->answerFromOneGuide($conversation, $text, $sure, $explain, $searchScores->get($sure->id) ?? $bestScore);
        }

        $context = $guides->map(fn (HelpArticle $article, int $i) => 'GUIDE '.($i + 1).": {$article->title}\nPAGE: {$article->admin_page}\n"
            .($explain ? "ABOUT: {$article->page_summary}\n" : '')."STEPS:\n{$article->steps}")->implode("\n\n");

        /** "How do I cancel this order?": give the model the real order, so it can say what applies to it. */
        $about = $conversation->context['order_summary'] ?? null;
        $orderNote = $about !== null && $this->parser->pointsAtOrder($text)
            ? "\nThe question is about this order from their store (use it only where it matters, e.g. it is already paid or fulfilled):\n{$about}\n"
            : '';

        $actionNote = $this->parser->asksToDoAction($text)
            ? "They asked you to do it for them. You cannot change anything in their store and must never say it was done. Explain how they can do it.\n"
            : '';
        $legalNote = $this->parser->asksLegalQuestion($text)
            ? "This is a legal question. Never say whether something is legal or required in their country. Only repeat what the guide says Shopify provides and what the store owner must check.\n"
            : '';
        $storeDataOption = $offerStoreData
            ? 'If they only want to SEE information from their own store (a number, a status, a price, a list, a setting), answer only: SOURCE: D. '
                ."If they want to do or change something, pick a guide.\n"
            : '';

        $instructions = "You are a helpful assistant inside the Shopify admin, talking to the store owner.\n"
            ."Answer using ONLY the numbered guides below.\n"
            .($legalNote !== ''
                ? "Your first line must be exactly: SOURCE: <number of the guide about the same topic>, or SOURCE: 0 if no guide is about that topic.\n"
                : "Your first line must be exactly: SOURCE: <number of the guide for the same task>, or SOURCE: 0 if no guide is about the same task.\n"
                    ."A guide counts even if it describes the task in different words. Answer 0 only when every guide is about a different task.\n")
            .$storeDataOption.$actionNote.$legalNote
            ."Then answer in 2 to 6 short numbered steps or sentences taken from that guide only. Name buttons and menus exactly as the guide does.\n"
            ."Never invent buttons, rules or links, and never write a URL.\n".$orderNote."\n".$context;

        $this->status(self::STATUS_WRITING);

        try {
            $this->streamed = $this->onDelta !== null;
            $answer = $this->writer->answerWithSource($instructions, $text, $this->onDelta);
        } catch (Throwable $e) {
            report($e);
            $this->streamed = false;
            $answer = ['text' => '', 'source' => 1, 'source_confidence' => null];
        }

        if ($answer['source'] === -1 && $offerStoreData) {
            return null;
        }

        if ($answer['source'] === 0) {
            return $offerStoreData ? null : $this->notSureWhichGuide($text, $bestScore, $answer['source_confidence']);
        }

        /** No usable pick (model down or ignored the format): fall back to the closest guide, shown as written. */
        $chosen = $guides->get(($answer['source'] ?? 1) - 1) ?? $guides->first();
        $body = $this->withoutLinks($answer['text']);
        $text = $body !== '' && $answer['source'] !== null ? $body : "{$chosen->title}:\n{$chosen->steps}";

        $conversation->update(['last_help_article_id' => $chosen->id]);

        return new BotReply($text, $chosen->admin_path, "Open {$chosen->admin_page}", $chosen->source_url,
            target: "guide:{$chosen->slug}", pipeline: BotReply::PIPELINE_RAG_LLM,
            scores: ['search' => $searchScores->get($chosen->id) ?? $bestScore, 'llm' => $answer['source_confidence']]);
    }

    /**
     * No guide clearly fits: say so and list the closest ones, rather than presenting a
     * loosely related guide as the answer.
     */
    /**
     * The guide search is clearly sure of, if any (see GUIDE_SURE_SCORE).
     *
     * @param  Collection<int, array{article: HelpArticle, score: float}>  $found
     */
    private function guideSearchIsSure(?HelpArticle $keyword, Collection $found): ?HelpArticle
    {
        $top = $found->first();

        if ($top === null) {
            return null;
        }

        $lead = $top['score'] - ($found->get(1)['score'] ?? 0.0);

        return $keyword?->is($top['article']) || ($top['score'] >= self::GUIDE_SURE_SCORE && $lead >= self::GUIDE_SURE_GAP)
            ? $top['article']
            : null;
    }

    /**
     * Answer from the one guide search is sure of: no guide choice for the model to get wrong.
     */
    private function answerFromOneGuide(Conversation $conversation, string $text, HelpArticle $guide, bool $explain, ?float $searchScore): BotReply
    {
        $about = $conversation->context['order_summary'] ?? null;
        $orderNote = $about !== null && $this->parser->pointsAtOrder($text)
            ? "\nThe question is about this order from their store (use it only where it matters, e.g. it is already paid or fulfilled):\n{$about}\n"
            : '';
        $actionNote = $this->parser->asksToDoAction($text)
            ? "They asked you to do it for them. You cannot change anything in their store and must never say it was done. Explain how they can do it.\n"
            : '';
        $legalNote = $this->parser->asksLegalQuestion($text)
            ? "This is a legal question. Never say whether something is legal or required in their country. Only repeat what the guide says Shopify provides and what the store owner must check.\n"
            : '';

        $instructions = "You are a helpful assistant inside the Shopify admin, talking to the store owner.\n"
            ."Answer using ONLY the guide below.\n".$actionNote.$legalNote
            ."Answer in 2 to 6 short numbered steps or sentences taken from the guide only. Name buttons and menus exactly as the guide does.\n"
            ."Never invent buttons, rules or links, and never write a URL.\n".$orderNote
            ."\nGUIDE: {$guide->title}\nPAGE: {$guide->admin_page}\n".($explain ? "ABOUT: {$guide->page_summary}\n" : '')."STEPS:\n{$guide->steps}";

        $answer = $this->write($instructions, $text, "{$guide->title}:\n{$guide->steps}");

        $conversation->update(['last_help_article_id' => $guide->id]);

        return new BotReply($answer, $guide->admin_path, "Open {$guide->admin_page}", $guide->source_url,
            target: "guide:{$guide->slug}", pipeline: BotReply::PIPELINE_RAG_LLM, scores: ['search' => $searchScore, 'llm' => null]);
    }

    private function notSureWhichGuide(string $text, ?float $searchScore = null, ?float $llmConfidence = null): BotReply
    {
        $closest = HelpArticle::search($text)->limit(3)->pluck('title');
        $cantDo = $this->parser->asksToDoAction($text) ? self::CANT_CHANGE_STORE.' ' : '';
        $search = 'https://help.shopify.com/en/search?q='.rawurlencode(implode(' ', HelpArticle::contentWords($text)));

        if ($closest->isEmpty()) {
            return new BotReply($cantDo."I don't have a guide for that yet. The Shopify Help Center link below searches for it.", helpUrl: $search, target: 'unsure',
                pipeline: BotReply::PIPELINE_RAG, scores: ['search' => $searchScore, 'llm' => $llmConfidence]);
        }

        return new BotReply(
            $cantDo."I'm not sure I have the right guide for that. The closest ones I have are:\n"
                .$closest->map(fn (string $title) => "• {$title}")->implode("\n")
                ."\nAsk about one of these, or use the Help Center link below.",
            helpUrl: $search,
            target: 'unsure',
            pipeline: BotReply::PIPELINE_RAG,
            scores: ['search' => $searchScore, 'llm' => $llmConfidence],
        );
    }

    /**
     * The guide the merchant is still talking about: our last answer was a guide and this
     * message points back at it ("the option here says…"). Any sign of a new topic wins:
     * store data, an order, a period, a policy, a page question, or a how-to that clearly
     * matches a different guide. The merchant can change the subject at any time.
     */
    private function guideBeingDiscussed(Conversation $conversation, string $text, ?ChatIntent $previousIntent): ?HelpArticle
    {
        if (! in_array($previousIntent, [ChatIntent::HowTo, ChatIntent::ExplainPage], true) || ! $this->parser->refersToPrevious($text)) {
            return null;
        }

        $startsNewTopic = $this->parser->orderNumber($text) !== null
            || preg_match('/\b(this|that|current)\s+(page|screen|section)\b/i', $text)
            || $this->parser->isSmallTalk($text) || $this->parser->asksAboutAssistant($text)
            || $this->parser->dateRange($text, $this->timezone($conversation)) !== null
            || $this->parser->asksForOrderList($text) || $this->parser->asksForProductList($text)
            || $this->parser->asksForLowStock($text) || $this->parser->asksForProductCount($text)
            || preg_match('/\bpolic(y|ies)\b/i', $text);

        if ($startsNewTopic) {
            return null;
        }

        $guide = $conversation->lastHelpArticle()->first();

        if ($guide === null) {
            return null;
        }

        /** "How do I refund an order then?" after the create-order guide is a new how-to, not a follow-up. */
        if ($this->parser->isHowToQuestion($text)) {
            $match = HelpArticle::confidentMatch($text);

            if ($match !== null && $match->isNot($guide)) {
                return null;
            }
        }

        return $guide;
    }

    /**
     * Answers a follow-up from the guide just shown, plus the closest other guide in case the
     * merchant has moved one step further (e.g. "Create order" → "Collect payment").
     */
    /**
     * "Can you explain the second step?" stays on the guide just shown. It moves to another guide only when the
     * keyword match and the closest meaning both name it ("there's an option saying collect payment").
     */
    private function guideFollowUp(Conversation $conversation, HelpArticle $guide, string $text): BotReply
    {
        $this->status(self::STATUS_SEARCHING);

        try {
            $closest = $this->guideSearch->closest($text, 1)[0] ?? null;
        } catch (Throwable $e) {
            report($e);
            $closest = null;
        }

        $keyword = HelpArticle::confidentMatch($text);
        $other = $closest !== null && $keyword?->is($closest['article']) && $keyword->isNot($guide) ? $keyword : null;

        return $this->answerFromOneGuide($conversation, $text, $other ?? $guide, false, $closest['score'] ?? null);
    }

    private function findOrder(Conversation $conversation, string $text): BotReply
    {
        $range = $this->parser->dateRange($text, $this->timezone($conversation));

        /** Already about one order, so a bare number is its number ("status of 1055") unless it could be a year. */
        $number = $this->parser->orderNumber($text) ?? ($range === null ? $this->parser->anyNumber($text) : null);

        if ($number !== null) {
            return $this->describeOrder($conversation, ltrim($number, '0') ?: $number);
        }

        if ($this->parser->asksForLatestOrder($text)) {
            $order = $this->store->latestOrder($conversation->shop);

            return $order === null ? new BotReply('Your store has no orders yet.') : $this->orderReply($order);
        }

        /** "The order from yesterday": show that day's orders so the merchant can pick one. */
        if ($range !== null || $this->parser->asksForOrderList($text)) {
            return $this->listOrders($conversation, $range);
        }

        /** Searching by customer, email or tracking number isn't built yet: say so instead of asking for a number they don't have. */
        if (preg_match('/@|\b(customer\s+(?:named|called)|from\s+(?:customer|a\s+customer\s+named)|named|e-?mail|tracking|forgot|don\'?t\s+(?:have|know|remember))\b/i', $text)) {
            return new BotReply('I can only look orders up by their order number for now. To find one by customer name, email or tracking number, '
                .'use the search bar on the Orders page. If you have the number, send it and I\'ll show you the order.', '/orders', 'Open Orders', target: 'lookup_by_number_only');
        }

        /** No order named, but a real product is ("do I still have the blue hoodie in large"): it's a product question. */
        if (($terms = $this->parser->productSearchTerms($text)) !== null && ($products = $this->findProductsLoosely($conversation, $terms)) !== []) {
            $this->decision = $this->decision?->overruledBy(ChatIntent::Products, 'product_named');

            return $this->foundProducts($terms, $products);
        }

        /** "this order", "it": the order shown last in this chat. Checked last, so dates and customer searches win. */
        if (($remembered = $conversation->context['order'] ?? null) !== null && $this->parser->pointsAtOrder($text)) {
            return $this->describeOrder($conversation, $remembered);
        }

        return $this->askFollowUp($conversation, AwaitingAnswer::OrderNumber, ChatIntent::FindOrder,
            'Which order do you mean? Send me the order number, for example #1001.');
    }

    private function describeOrder(Conversation $conversation, string $number): BotReply
    {
        $order = $this->store->findOrderByNumber($conversation->shop, $number);

        if ($order === null) {
            return $this->askFollowUp($conversation, AwaitingAnswer::OrderNumber, ChatIntent::FindOrder,
                "I couldn't find order #{$number} in your store. Check the number and send it again. "
                .'(I can only see orders from about the last '.MessageParser::ORDER_HISTORY_DAYS.' days.)');
        }

        return $this->orderReply($order);
    }

    /**
     * @param  array{number: string, created_at: CarbonInterface, financial_status: string, fulfillment_status: string, total: string, currency: string, items: list<string>, tracking_number: string|null, admin_path: string}  $order
     */
    private function orderReply(array $order): BotReply
    {
        $lines = [
            "Order #{$order['number']}, placed {$order['created_at']->format('M j, g:i A')}:",
            '• Payment: '.str_replace('_', ' ', $order['financial_status']),
            '• Fulfillment: '.str_replace('_', ' ', $order['fulfillment_status']),
            '• Items: '.implode(', ', $order['items']),
            "• Total: {$order['total']} {$order['currency']}",
        ];

        if ($order['tracking_number'] !== null) {
            $lines[] = "• Tracking number: {$order['tracking_number']}";
        }

        return new BotReply(implode("\n", $lines), $order['admin_path'], "Open order #{$order['number']}", target: "order:{$order['number']}");
    }

    private function orderStats(Conversation $conversation, string $text): BotReply
    {
        $range = $this->parser->dateRange($text, $this->timezone($conversation));
        $filter = $this->parser->orderFilter($text);
        $lastPeriod = $conversation->context['period'] ?? null;
        $lastView = $conversation->context['order_view'] ?? null;
        $isFollowUp = $this->decision?->decidedBy() === 'topic_follow_up';

        /** "What about the day before?" moves the last period back by its own length, and keeps what was measured. */
        $movesBack = $range === null && $lastPeriod !== null && $this->parser->asksForPreviousPeriod($text);

        if ($movesBack) {
            $range = $this->periodBefore($conversation, $lastPeriod);
        } elseif ($range === null && $isFollowUp && $lastPeriod !== null) {
            /** "But I want unfulfilled": the same period as the last answer, now filtered. */
            $timezone = $this->timezone($conversation);
            $range = ['from' => CarbonImmutable::parse($lastPeriod['from'])->setTimezone($timezone), 'to' => CarbonImmutable::parse($lastPeriod['to'])->setTimezone($timezone), 'label' => $lastPeriod['label'] ?? ''];
        }

        $wantsSales = $this->parser->asksForRevenue($text) || (($movesBack || $isFollowUp) && ($lastPeriod['metric'] ?? null) === 'revenue');
        $wantsList = $this->parser->asksForOrderList($text) || $filter->sort !== null || ($isFollowUp && $lastView === 'list');

        /** "How many unfulfilled orders?" with no dates: every order this app can see. */
        if ($range === null && ! $filter->isEmpty() && ! $wantsList) {
            $range = $this->parser->dateRange('all time', $this->timezone($conversation));
        }

        $conversation->update(['context' => [...($conversation->context ?? []),
            'order_view' => $wantsSales ? 'revenue' : ($wantsList ? 'list' : 'count'),
            ...($range !== null ? ['period' => [
                'from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(), 'label' => $range['label'], 'metric' => $wantsSales ? 'revenue' : 'orders',
            ]] : []),
        ]]);

        if ($range !== null && $this->outsideOrderHistory($conversation, $range)) {
            return new BotReply("I can't answer that: this app can only see orders from about the last ".MessageParser::ORDER_HISTORY_DAYS.' days, '
                .'so I have no data for orders placed '.$range['label'].'. Your Orders page in the Shopify admin shows older orders.', '/orders', 'Open Orders', target: 'history_limit');
        }

        if ($wantsSales) {
            return $this->salesTotal($conversation, $range ?? $this->parser->dateRange('last 30 days', $this->timezone($conversation)));
        }

        /** Listing needs no dates: without any, it shows the newest orders. */
        if ($wantsList) {
            return $this->listOrders($conversation, $range, $filter);
        }

        $ranges = $this->parser->dateRanges($text, $this->timezone($conversation));

        if (count($ranges) >= 2 && preg_match('/\b(compare|compared|vs\.?|versus|more|fewer|less|than)\b/i', $text)) {
            return $this->compareOrderCounts($conversation, $ranges[0], $ranges[1]);
        }

        if ($range === null) {
            return $this->askFollowUp($conversation, AwaitingAnswer::DateRange, ChatIntent::OrderStats,
                'For which dates? '.self::DATE_EXAMPLES);
        }

        if (preg_match('/\b(average|avg|per\s+day|a\s+day|daily)\b/i', $text)) {
            return $this->averageOrdersPerDay($conversation, $range);
        }

        return $this->countOrders($conversation, $range, $filter);
    }

    /**
     * The period of the same length just before the remembered one: the day before, the week before.
     *
     * @param  array{from: string, to: string, metric: string}  $lastPeriod
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}
     */
    private function periodBefore(Conversation $conversation, array $lastPeriod): array
    {
        $timezone = $this->timezone($conversation);
        $lastFrom = CarbonImmutable::parse($lastPeriod['from'])->setTimezone($timezone);
        $days = max(1, (int) round($lastFrom->diffInDays(CarbonImmutable::parse($lastPeriod['to'])->setTimezone($timezone))));

        /** "This month" so far → the whole month before; "this week" so far → the whole week before. */
        if ($lastFrom->day === 1 && $days >= 7) {
            $from = $lastFrom->subMonthNoOverflow();

            return ['from' => $from, 'to' => $lastFrom, 'label' => 'in '.$from->format('F')];
        }

        if ($lastFrom->equalTo($lastFrom->startOfWeek()) && $days > 1 && $days <= 7) {
            $from = $lastFrom->subWeek();

            return ['from' => $from, 'to' => $lastFrom, 'label' => 'the week of '.$from->format('M j')];
        }

        $from = $lastFrom->subDays($days);

        return [
            'from' => $from,
            'to' => $lastFrom,
            'label' => $days === 1 ? 'on '.$from->format('M j') : 'from '.$from->format('M j').' to '.$lastFrom->subDay()->format('M j'),
        ];
    }

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, label: string}  $range
     */
    private function outsideOrderHistory(Conversation $conversation, array $range): bool
    {
        return $range['to']->lessThanOrEqualTo(now($this->timezone($conversation))->subDays(MessageParser::ORDER_HISTORY_DAYS));
    }

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, label: string}  $first
     * @param  array{from: CarbonInterface, to: CarbonInterface, label: string}  $second
     */
    private function compareOrderCounts(Conversation $conversation, array $first, array $second): BotReply
    {
        $a = $this->store->countOrders($conversation->shop, $first['from'], $first['to']);
        $b = $this->store->countOrders($conversation->shop, $second['from'], $second['to']);

        $verdict = match (true) {
            $a > $b => ($a - $b).' more '.$first['label'].'.',
            $a < $b => ($b - $a).' fewer '.$first['label'].'.',
            default => 'The same number both times.',
        };

        return new BotReply(
            ucfirst($first['label']).": {$a} ".($a === 1 ? 'order' : 'orders')."\n".ucfirst($second['label']).": {$b} ".($b === 1 ? 'order' : 'orders')."\n{$verdict}"
                .$this->orderHistoryNote($conversation, $first['from']->lessThan($second['from']) ? $first : $second),
            '/orders', 'Open Orders', target: 'order_comparison',
        );
    }

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, label: string}  $range
     */
    private function averageOrdersPerDay(Conversation $conversation, array $range): BotReply
    {
        $count = $this->store->countOrders($conversation->shop, $range['from'], $range['to']);
        $days = max(1, (int) round($range['from']->diffInDays(min($range['to'], now($this->timezone($conversation))->addSecond()))));

        return new BotReply(
            "{$count} ".($count === 1 ? 'order' : 'orders')." {$range['label']} over {$days} ".($days === 1 ? 'day' : 'days')
                .': about '.round($count / $days, 1).' a day.'.$this->orderHistoryNote($conversation, $range),
            '/orders', 'Open Orders', target: 'order_average',
        );
    }

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, label: string}  $range
     */
    private function countOrders(Conversation $conversation, array $range, ?OrderFilter $filter = null): BotReply
    {
        $count = $this->store->countOrders($conversation->shop, $range['from'], $range['to'], $filter);
        $what = $filter !== null && ! $filter->isEmpty() ? $filter->label() : 'orders';
        $one = $what === 'orders' ? 'order' : (string) preg_replace('/\borders\b/', 'order', $what, 1);

        $text = $count === 1
            ? "1 {$one} was created {$range['label']}."
            : "{$count} {$what} were created {$range['label']}.";

        return new BotReply($text.$this->orderHistoryNote($conversation, $range), '/orders', 'Open Orders');
    }

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, label: string}  $range
     */
    private function salesTotal(Conversation $conversation, array $range): BotReply
    {
        $sales = $this->store->salesTotal($conversation->shop, $range['from'], $range['to']);

        $text = $sales['orders'] === 0
            ? "No sales {$range['label']}: no orders were created."
            : 'Sales '.$range['label'].': '.number_format($sales['total'], 2)." {$sales['currency']} from {$sales['orders']} "
                .($sales['orders'] === 1 ? 'order' : 'orders').' (order totals after refunds, including shipping and tax).'
                .($sales['complete'] ? '' : ' That is the newest 2,500 orders only; Analytics has the full total.');

        return new BotReply($text.$this->orderHistoryNote($conversation, $range), '/analytics', 'Open Analytics', target: 'revenue');
    }

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, label: string}|null  $range
     */
    private function listOrders(Conversation $conversation, ?array $range, ?OrderFilter $filter = null): BotReply
    {
        $filter = $filter !== null && ! $filter->isEmpty() ? $filter : null;
        $what = $filter?->label() ?? 'orders';
        $orders = $this->store->recentOrders($conversation->shop, $range['from'] ?? null, $range['to'] ?? null, self::ORDER_LIST_SIZE, $filter);

        if ($orders === []) {
            return new BotReply(($range === null ? "I found no {$what} in the last ".MessageParser::ORDER_HISTORY_DAYS.' days.' : "No {$what} were created {$range['label']}.")
                .$this->orderHistoryNote($conversation, $range), '/orders', 'Open Orders');
        }

        $order = $filter?->sort === 'largest' ? 'biggest' : ($filter?->sort === 'smallest' ? 'smallest' : 'newest');

        if ($range === null) {
            $header = 'Your '.count($orders).' '.($filter?->sort !== null ? $order : 'most recent').' '.$what.':';
        } else {
            $count = $this->store->countOrders($conversation->shop, $range['from'], $range['to'], $filter);
            $header = ($count === 1 ? "1 {$what} was" : "{$count} {$what} were")." created {$range['label']}."
                .($count > count($orders) || $filter?->sort !== null ? ' The '.count($orders)." {$order}:" : '');
        }

        $lines = collect($orders)->map(fn (array $order) => "• #{$order['number']} · {$order['created_at']->format('M j')} · "
            .str_replace('_', ' ', $order['financial_status']).' · '.str_replace('_', ' ', $order['fulfillment_status'])
            ." · {$order['total']} {$order['currency']}");

        return new BotReply($header."\n".$lines->implode("\n").$this->orderHistoryNote($conversation, $range), '/orders', 'Open Orders');
    }

    /**
     * Honest note when the period reaches past the order history this app can see.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, label: string}|null  $range
     */
    private function orderHistoryNote(Conversation $conversation, ?array $range): string
    {
        $oldestVisible = now($this->timezone($conversation))->subDays(MessageParser::ORDER_HISTORY_DAYS);

        return $range !== null && $range['from']->lessThan($oldestVisible)
            ? "\n(I can only see orders from about the last ".MessageParser::ORDER_HISTORY_DAYS." days, so older orders aren't included.)"
            : '';
    }

    /**
     * "What is finance?" explains that page; "what is this page?" uses the page memory.
     */
    private function namedOrCurrentPage(Conversation $conversation, string $text): BotReply
    {
        $subject = $this->parser->pageQuestionSubject($text);
        $article = $subject === null ? null : HelpArticle::forPageNamed($subject);

        if ($article !== null) {
            return $this->explainPage($conversation, $article, fromMemory: false);
        }

        $words = $this->parser->pageSubjectWords($text);

        if ($words === null) {
            return $this->currentPage($conversation);
        }

        $article = $this->pageNamedIn($words);

        return $article !== null
            ? $this->explainPage($conversation, $article, fromMemory: false)
            : $this->answerFromGuides($conversation, $text, explain: true);
    }

    /**
     * The guide must contain more than half of the words in its title or keywords, so
     * "zebra dashboard" doesn't get the Analytics dashboard guide from one shared word.
     * Words are compared by their first 5 letters so "payouts" still finds "payout".
     */
    private function pageNamedIn(string $words): ?HelpArticle
    {
        $article = HelpArticle::forPageNamed($words) ?? HelpArticle::search($words, namesOnly: true)->first();

        if ($article === null) {
            return null;
        }

        $names = mb_strtolower("{$article->title} {$article->keywords}");
        $subjectWords = explode(' ', $words);
        $found = collect($subjectWords)->filter(fn (string $word) => str_contains($names, mb_substr($word, 0, 5)))->count();

        return $found * 2 > count($subjectWords) ? $article : null;
    }

    /**
     * "What is this page?" The app cannot see which admin page the merchant has open,
     * so it assumes the page it last sent them to, or asks.
     */
    private function currentPage(Conversation $conversation): BotReply
    {
        $article = $conversation->lastHelpArticle()->first();

        if ($article === null) {
            return $this->askFollowUp($conversation, AwaitingAnswer::PageName, ChatIntent::ExplainPage,
                'Which page are you on? Tell me its name, for example Orders, Inventory or Discounts.');
        }

        return $this->explainPage($conversation, $article, fromMemory: true);
    }

    private function explainPage(Conversation $conversation, HelpArticle $article, bool $fromMemory): BotReply
    {
        $conversation->update(['last_help_article_id' => $article->id]);

        $explanation = "{$article->page_summary}\n\nWhat you can do there:\n{$article->steps}";

        $intro = $fromMemory ? "If you're on the page I sent you to ({$article->admin_page}): " : '';

        return new BotReply($intro.$explanation, $article->admin_path, "Open {$article->admin_page}", $article->source_url, target: "guide:{$article->slug}", pipeline: BotReply::PIPELINE_GUIDE);
    }

    private function products(Conversation $conversation, string $text): BotReply
    {
        if ($this->decision?->decidedBy() === 'restock_advice') {
            return $this->restockAdvice($conversation);
        }

        /** "Products under $25 that are low in stock", "draft products", "what's out of stock". */
        $filter = $this->parser->productFilter($text);

        if (! $filter->isEmpty()) {
            return $this->filteredProducts($conversation, $filter);
        }

        if ($this->parser->asksForProductCount($text)) {
            return $this->countProducts($conversation);
        }

        if ($this->parser->asksForLowStock($text)) {
            return $this->lowStock($conversation);
        }

        if ($this->parser->asksForBestSellers($text)) {
            return $this->bestSellers($conversation, $text);
        }

        if ($this->parser->asksForProductList($text)) {
            return $this->listProducts($conversation);
        }

        /** "Their prices", "those": the products shown last in this chat. */
        if (! empty($conversation->context['products']) && $this->parser->pointsAtProducts($text)) {
            return $this->rememberedProducts($conversation);
        }

        $terms = $this->parser->productSearchTerms($text);

        if ($terms === null) {
            return $this->askFollowUp($conversation, AwaitingAnswer::ProductName, ChatIntent::Products,
                'Which product? Send me its name as it appears in your Products list.');
        }

        $products = $this->findProductsLoosely($conversation, $terms);

        if ($products !== []) {
            return $this->foundProducts($terms, $products);
        }

        /** The engine said "products" but nothing matches and no product word was used: not a product question. */
        if (! $this->parser->mentionsProducts($text)) {
            return $this->outOfScope();
        }

        /** "Which product should I list next?" isn't a product name: answer from the guides, don't ask for one. */
        if ($this->parser->looksLikeQuestion($text) && preg_match('/\b(should|which|why|how|what|best\s+way)\b/i', $text)) {
            $this->decision = $this->decision->overruledBy(ChatIntent::HowTo, 'not_a_product_name');

            return $this->answerFromGuides($conversation, $text);
        }

        return $this->askFollowUp($conversation, AwaitingAnswer::ProductName, ChatIntent::Products,
            "I couldn't find a product matching \"{$terms}\". Send me the product name as it appears in your admin.");
    }

    /**
     * Products matching the words, retrying with the first two words: "blue hoodie large" has a size
     * no title contains, but "blue hoodie" finds it.
     *
     * @return list<array<string, mixed>>
     */
    private function findProductsLoosely(Conversation $conversation, string $terms): array
    {
        $products = $this->store->findProducts($conversation->shop, $terms);
        $words = explode(' ', $terms);

        if ($products === [] && count($words) > 2) {
            $products = $this->store->findProducts($conversation->shop, implode(' ', array_slice($words, 0, 2)));
        }

        return $products;
    }

    /**
     * Price and stock of each product shown last, looked up again so the numbers are current.
     */
    private function rememberedProducts(Conversation $conversation): BotReply
    {
        $titles = array_slice($conversation->context['products'], 0, 5);
        $products = collect($titles)
            ->map(fn (string $title) => collect($this->store->findProducts($conversation->shop, $title))->firstWhere('title', $title))
            ->filter()
            ->values();

        if ($products->isEmpty()) {
            return $this->askFollowUp($conversation, AwaitingAnswer::ProductName, ChatIntent::Products,
                'Which product? Send me its name as it appears in your Products list.');
        }

        return new BotReply(
            $products->map(fn (array $product) => $this->productReply($product)->text)->implode("\n\n"),
            $products->count() === 1 ? $products[0]['admin_path'] : '/products',
            $products->count() === 1 ? "Open {$products[0]['title']}" : 'Open Products',
            target: 'products:'.$products->pluck('title')->implode('|'),
        );
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $products
     */
    private function foundProducts(string $terms, array $products): BotReply
    {
        if (count($products) === 1) {
            return $this->productReply($products[0]);
        }

        $lines = collect($products)->map(fn (array $product) => "• {$product['title']}: ".$this->stockLabel($product));

        return new BotReply(
            'I found '.count($products)." products matching \"{$terms}\":\n".$lines->implode("\n")."\nSend me the full name to see prices and stock per variant.",
            '/products',
            'Open Products',
            target: 'products:'.collect($products)->pluck('title')->implode('|'),
        );
    }

    /**
     * @param  array{title: string, status: string, total_inventory: int, tracks_inventory: bool, currency: string, variants: list<array{title: string, price: string, inventory_quantity: int|null, sku: string|null}>, admin_path: string}  $product
     */
    private function productReply(array $product): BotReply
    {
        $lines = ["{$product['title']} ({$product['status']})", '• Total stock: '.$this->stockLabel($product)];

        foreach (array_slice($product['variants'], 0, 10) as $variant) {
            $name = $variant['title'] === 'Default Title' ? 'Price' : $variant['title'];
            $stock = ! $product['tracks_inventory'] || $variant['inventory_quantity'] === null
                ? ''
                : ', '.($variant['inventory_quantity'] > 0 ? "{$variant['inventory_quantity']} in stock" : 'out of stock');

            $lines[] = "• {$name}: {$variant['price']} {$product['currency']}{$stock}";
        }

        return new BotReply(implode("\n", $lines), $product['admin_path'], "Open {$product['title']}", target: "product:{$product['title']}");
    }

    /**
     * @param  array{total_inventory: int, tracks_inventory: bool}  $product
     */
    private function stockLabel(array $product): string
    {
        return match (true) {
            ! $product['tracks_inventory'] => 'inventory not tracked',
            $product['total_inventory'] > 0 => "{$product['total_inventory']} in stock",
            default => 'out of stock',
        };
    }

    /**
     * Low stock is checked per variant (a size can sell out while the product total looks fine).
     * When nothing is low, the lowest levels are still shown so the answer is useful.
     */
    private function lowStock(Conversation $conversation): BotReply
    {
        $variants = collect($this->store->lowestStockVariants($conversation->shop));
        $untrackedNote = "\nProducts that don't track inventory have no stock count in Shopify, so they aren't included.";

        if ($variants->isEmpty()) {
            return new BotReply("None of your active products track inventory, so Shopify has no stock counts to check.\n"
                .'Turn on "Track quantity" for a product to see its stock here.', '/products/inventory', 'Open Inventory');
        }

        $low = $variants->filter(fn (array $variant) => $variant['quantity'] <= self::LOW_STOCK_THRESHOLD);

        if ($low->isEmpty()) {
            return new BotReply(
                'Nothing is low: no tracked product has '.self::LOW_STOCK_THRESHOLD." or fewer in stock.\nLowest stock right now:\n"
                    .$variants->take(3)->map(fn (array $variant) => $this->stockLevelLine($variant))->implode("\n")
                    .$untrackedNote,
                '/products/inventory',
                'Open Inventory',
                target: 'products:'.$variants->take(3)->pluck('product_title')->unique()->implode('|'),
            );
        }

        $shown = $low->take(15);
        $more = $low->count() > $shown->count() ? "\n…and ".($low->count() - $shown->count()).' more.' : '';

        return new BotReply(
            $low->count().' '.($low->count() === 1 ? 'variant has' : 'variants have').' '.self::LOW_STOCK_THRESHOLD." or fewer in stock:\n"
                .$shown->map(fn (array $variant) => $this->stockLevelLine($variant))->implode("\n").$more.$untrackedNote,
            '/products/inventory',
            'Open Inventory',
            target: 'products:'.$shown->pluck('product_title')->unique()->implode('|'),
        );
    }

    /**
     * @param  array{product_title: string, variant_title: string, quantity: int}  $variant
     */
    private function stockLevelLine(array $variant): string
    {
        $name = $variant['variant_title'] === 'Default Title'
            ? $variant['product_title']
            : "{$variant['product_title']} ({$variant['variant_title']})";

        return "• {$name}: ".($variant['quantity'] > 0 ? "{$variant['quantity']} left" : 'out of stock');
    }

    /**
     * Products by units sold, from real orders in the period asked for (default: last 30 days).
     */
    private function bestSellers(Conversation $conversation, string $text): BotReply
    {
        $timezone = $this->timezone($conversation);
        $range = $this->parser->dateRange($text, $timezone)
            ?? ['from' => now($timezone)->subDays(30), 'to' => now($timezone)->addMinute(), 'label' => 'in the last 30 days'];

        if ($this->outsideOrderHistory($conversation, $range)) {
            return new BotReply("I can't answer that: this app can only see orders from about the last ".MessageParser::ORDER_HISTORY_DAYS.' days.', '/orders', 'Open Orders', target: 'history_limit');
        }

        $sellers = collect($this->store->bestSellers($conversation->shop, $range['from'], $range['to']));

        if ($sellers->isEmpty()) {
            return new BotReply("No products were sold {$range['label']}.", '/products', 'Open Products', target: 'best_sellers');
        }

        $lines = $sellers->values()->map(fn (array $seller, int $i) => ($i + 1).". {$seller['title']}: {$seller['quantity']} sold"
            .($seller['revenue'] > 0 ? ', '.number_format($seller['revenue'], 2)." {$seller['currency']}" : ''));

        return new BotReply("Your best sellers {$range['label']}:\n".$lines->implode("\n").$this->orderHistoryNote($conversation, $range),
            '/products', 'Open Products', target: 'products:'.$sellers->pluck('title')->implode('|'));
    }

    /**
     * Status and stock come from Shopify's product search; price and "fewer than N left" are checked here
     * on each variant. Says so when the store has more matching products than one check reads.
     */
    private function filteredProducts(Conversation $conversation, ProductFilter $filter): BotReply
    {
        $scanned = $this->store->listProducts($conversation->shop, self::PRODUCT_FILTER_SCAN, $filter);
        $below = $filter->fewerThan ?? self::LOW_STOCK_THRESHOLD + 1;

        $matching = collect($scanned)
            ->filter(fn (array $product) => $filter->priceFits($product))
            ->filter(fn (array $product) => $filter->stock !== 'low' || ($product['tracks_inventory']
                && collect($product['variants'])->contains(fn (array $variant) => $variant['inventory_quantity'] !== null && $variant['inventory_quantity'] < $below)))
            ->values();

        $partial = count($scanned) >= self::PRODUCT_FILTER_SCAN
            ? "\n(I checked your first ".self::PRODUCT_FILTER_SCAN.' products A–Z. Products in your admin can filter all of them.)'
            : '';

        if ($matching->isEmpty()) {
            return new BotReply("You have no {$filter->label()}.".$partial, '/products', 'Open Products', target: 'products:');
        }

        $lines = $matching->take(15)->map(function (array $product) {
            $prices = collect($product['variants'])->pluck('price')->map(fn ($price) => (float) $price);
            $price = $prices->min() === $prices->max()
                ? number_format($prices->min(), 2)
                : number_format($prices->min(), 2).'–'.number_format($prices->max(), 2);

            return "• {$product['title']}".($product['status'] === 'active' ? '' : " ({$product['status']})")
                .": {$price} {$product['currency']} · ".$this->stockLabel($product);
        });

        $more = $matching->count() > $lines->count() ? "\n…and ".($matching->count() - $lines->count()).' more.' : '';

        return new BotReply($matching->count().' '.($matching->count() === 1 ? (string) preg_replace('/\bproducts\b/', 'product', $filter->label(), 1) : $filter->label()).":\n"
            .$lines->implode("\n").$more.$partial, '/products', 'Open Products',
            target: 'products:'.$matching->take(15)->pluck('title')->implode('|'));
    }

    private function listProducts(Conversation $conversation): BotReply
    {
        $products = $this->store->listProducts($conversation->shop, self::PRODUCT_LIST_SIZE);

        if ($products === []) {
            return new BotReply("Your store doesn't have any products yet.", '/products/new', 'Add a product');
        }

        $total = $this->store->countProducts($conversation->shop);
        $lines = collect($products)->map(fn (array $product) => "• {$product['title']}"
            .($product['status'] === 'active' ? '' : " ({$product['status']})")
            .': '.$this->stockLabel($product));

        $heading = $total > count($products)
            ? 'Your first '.count($products)." of {$total} products (A–Z):"
            : "Your {$total} ".($total === 1 ? 'product' : 'products').':';

        return new BotReply("{$heading}\n".$lines->implode("\n")."\nSend me a product name to see its prices and stock per variant.", '/products', 'Open Products',
            target: 'products:'.collect($products)->pluck('title')->implode('|'));
    }

    private function countProducts(Conversation $conversation): BotReply
    {
        $total = $this->store->countProducts($conversation->shop);
        $active = $this->store->countProducts($conversation->shop, 'ACTIVE');
        $draft = $this->store->countProducts($conversation->shop, 'DRAFT');
        $archived = max(0, $total - $active - $draft);

        $text = "You have {$total} ".($total === 1 ? 'product' : 'products').": {$active} active, {$draft} draft"
            .($archived > 0 ? ", {$archived} archived." : '.');

        return new BotReply($text, '/products', 'Open Products');
    }

    private function policy(Conversation $conversation, string $text): BotReply
    {
        /** "Does that apply internationally?" right after a policy: the same policy. */
        $type = $this->parser->policyType($text)
            ?? ($this->decision?->decidedBy() === 'topic_follow_up' ? ($conversation->context['policy'] ?? null) : null);
        $policies = collect($this->store->policies($conversation->shop));

        if ($type === null) {
            return new BotReply($policies->isEmpty()
                ? "Your store doesn't have any policies filled in yet. You can add them in Settings → Policies."
                : 'Your store has these policies: '.$policies->pluck('title')->implode(', ').'. Which one do you want to know about?',
                '/settings/legal', 'Open Policies', target: 'ask:PolicyType');
        }

        $policy = $policies->firstWhere('type', $type);

        if ($policy === null) {
            $name = strtolower(str_replace('_', ' ', $type));

            return new BotReply("Your store doesn't have a {$name} set up yet. You can add one in Settings → Policies.", '/settings/legal', 'Open Policies', target: "policy:{$type}");
        }

        $answer = $this->write(
            instructions: "You are a helpful assistant inside the Shopify admin, talking to the store owner about their own store.\n"
                ."Answer their question using ONLY the policy text below, in 2 to 4 short sentences.\n"
                ."Do not add rules, numbers or exceptions that are not in the text.\n\n"
                ."POLICY: {$policy['title']}\n".Str::limit($policy['body'], 3000),
            message: $text,
            fallback: Str::limit($policy['body'], 600),
        );

        return new BotReply($answer, '/settings/legal', 'Open Policies', target: "policy:{$type}", pipeline: BotReply::PIPELINE_DATA_LLM);
    }

    private function storeDetails(Conversation $conversation): BotReply
    {
        $details = $this->store->storeDetails($conversation->shop);

        $lines = collect([
            'Name' => $details['name'],
            'Plan' => $details['plan'],
            'Currency' => $details['currency'],
            'Timezone' => $details['timezone'],
            'Domain' => $details['domain'],
            'Shopify domain' => $details['myshopify_domain'],
            'Customer contact email' => $details['contact_email'],
            'Country' => $details['country'],
        ])->filter(fn (?string $value) => filled($value))->map(fn (string $value, string $label) => "• {$label}: {$value}");

        /** The private store/owner email is left out: every staff member can use this assistant. */
        return new BotReply("Your store details:\n".$lines->implode("\n")."\n(The private store email isn't shown here; it's in Settings → General.)",
            '/settings/general', 'Open Store details');
    }

    private function discounts(Conversation $conversation): BotReply
    {
        $discounts = $this->store->activeDiscounts($conversation->shop);

        if ($discounts === []) {
            return new BotReply('You have no active discounts right now.', '/discounts', 'Open Discounts');
        }

        $lines = collect($discounts)->map(fn (array $discount) => '• '.$discount['title']
            .($discount['code'] !== null ? " (code {$discount['code']})" : ' (automatic)')
            .($discount['summary'] !== null ? ": {$discount['summary']}" : '')
            .($discount['ends_at'] !== null ? ', ends '.$discount['ends_at']->format('M j') : ''));

        return new BotReply("Active discounts:\n".$lines->implode("\n"), '/discounts', 'Open Discounts');
    }

    private function collections(Conversation $conversation): BotReply
    {
        $collections = $this->store->collections($conversation->shop);

        if ($collections === []) {
            return new BotReply("You don't have any collections yet.", '/collections', 'Open Collections');
        }

        $lines = collect($collections)->map(fn (array $collection) => "• {$collection['title']}: {$collection['products_count']} "
            .($collection['products_count'] === 1 ? 'product' : 'products'));

        return new BotReply("Your collections:\n".$lines->implode("\n"), '/collections', 'Open Collections');
    }

    private function storeUnavailable(ShopifyApiError $error): BotReply
    {
        return new BotReply($error->isAccessDenied()
            ? "This app doesn't have permission to read that data yet. "
                .'Check that the access scopes and protected customer data access are approved, then reload the app.'
            : "I couldn't reach your store data right now. Please try again in a moment.");
    }

    /**
     * The reply for a rule that already knows the answer: a greeting, private data refused, the future not counted.
     */
    private function fixedReply(string $text): BotReply
    {
        return match ($this->decision->decidedBy()) {
            'small_talk' => $this->smallTalk($text),
            'about_assistant' => new BotReply("I'm your store assistant. I can read your store and explain Shopify, but I can't change anything in it.\n"
                ."I can: count and look up orders, show sales, best sellers and stock, read your policies, discounts and store settings, "
                ."explain any admin page, walk you through tasks step by step, and suggest ways to grow your sales.\n"
                .'I can\'t: edit products or prices, cancel or refund orders, or send emails. I\'ll explain how you can do those.',
                target: 'out_of_scope', pipeline: BotReply::PIPELINE_NONE),
            'about_me' => new BotReply("I can't see your personal details, only your store's data. Ask me \"what are my store details?\" "
                .'to see your store name, plan and settings.', target: 'out_of_scope', pipeline: BotReply::PIPELINE_NONE),
            'complaint' => new BotReply('Sorry, I got that wrong. Tell me in a few words what you want to know or do in your store and I\'ll try again. '
                .'For example: "orders this week", "how do I refund an order" or "what is the Content page".',
                target: 'out_of_scope', pipeline: BotReply::PIPELINE_NONE),
            'private_data' => new BotReply("I can't share passwords, card numbers, API keys or other secrets, and I have no access to your customers' "
                .'personal details. To see a customer\'s details, open Customers in your admin; your staff permissions control who can see them.',
                '/customers', 'Open Customers', target: 'out_of_scope', pipeline: BotReply::PIPELINE_NONE),
            'unclear_reference' => new BotReply("I can't see your screen. Which setting do you mean? Tell me its name or the page it's on, "
                .'for example "the cookie banner in Customer privacy".', target: 'ask:SettingName', pipeline: BotReply::PIPELINE_ASK),
            default => new BotReply("I can't predict future orders or sales; I can only count what has already happened. "
                .'Ask me, for example, "how many orders this month compared with last month?" or "how can I grow my sales?"',
                target: 'future', pipeline: BotReply::PIPELINE_NONE),
        };
    }

    /**
     * "Hey" gets a greeting and "thanks" a welcome, not the out-of-scope introduction.
     */
    private function smallTalk(string $text): BotReply
    {
        $reply = match (true) {
            $this->parser->asksHowAreYou($text) => "I'm doing well, thanks for asking! What can I help you with in your store today?",
            (bool) preg_match('/\b(thanks|thank\s+you|thx)\b/i', $text) => "You're welcome! Ask me anything else about your store.",
            (bool) preg_match('/\b(bye|goodbye)\b/i', $text) => 'Bye! Come back any time.',
            (bool) preg_match('/^\s*(ok|okay|cool|great)\b/i', $text) => 'Great. What would you like to know next?',
            default => "Hi! What can I help you with in your store today? For example: \"how many orders yesterday?\", "
                .'"how much did I earn this month?", "which products are low on stock?" or "how do I create a discount?"',
        };

        return new BotReply($reply, target: 'small_talk', pipeline: BotReply::PIPELINE_NONE);
    }

    private function outOfScope(): BotReply
    {
        return new BotReply("I'm your store assistant. I can help with how-to questions in your Shopify admin, your orders, products and stock, "
            .'store policies, store details, discounts and collections. '
            .'For example: "how many orders yesterday?", "which products are low on stock?" or "what is my refund policy?"', target: 'out_of_scope', pipeline: BotReply::PIPELINE_NONE);
    }

    private function askFollowUp(Conversation $conversation, AwaitingAnswer $awaiting, ChatIntent $intent, string $question): BotReply
    {
        $conversation->update(['awaiting' => $awaiting, 'pending_intent' => $intent]);

        return new BotReply($question, target: "ask:{$awaiting->name}", pipeline: BotReply::PIPELINE_ASK);
    }

    /**
     * Ask the chat model to rewrite help text. If it is down or returns nothing, the
     * merchant still gets the original text.
     */
    private function write(string $instructions, string $message, string $fallback, int $maxTokens = 250): string
    {
        $this->status(self::STATUS_WRITING);

        try {
            if ($this->onDelta === null) {
                $reply = $this->writer->reply($instructions, $message, $maxTokens);
            } else {
                $this->streamed = true;
                $reply = $this->writer->stream($instructions, $message, $this->onDelta, $maxTokens);
            }
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }

        $reply = $this->withoutLinks($reply);

        return $reply !== '' ? $reply : $fallback;
    }

    /**
     * Links come only from our own guides: a URL written by the model may not exist, so it is removed.
     */
    private function withoutLinks(string $text): string
    {
        return trim((string) preg_replace(['/\[([^\]]+)\]\(\s*https?:\/\/[^)]*\)/i', '/\(?\bhttps?:\/\/\S+\)?/i', '/[ \t]+([.,])/'], ['$1', '', '$1'], $text));
    }

    /**
     * Who produced the answer, shown under it: the router (Laya, a rule, or conversation memory),
     * then what answered after it ("Laya → RAG → LLM", "Rule → Shopify data").
     */
    private function answeredBy(RoutingDecision $decision, BotReply $reply): string
    {
        $router = match ($decision->steps[0] ?? null) {
            'laya' => 'Laya',
            'laya_failed' => 'Laya (unavailable)',
            'guide_follow_up' => 'Memory',
            'follow_up_answer' => 'Follow-up',
            default => 'Rule',
        };

        $after = match ($reply->pipeline) {
            BotReply::PIPELINE_DATA => ['Shopify data'],
            BotReply::PIPELINE_DATA_LLM => ['Shopify data', 'LLM'],
            BotReply::PIPELINE_RAG_LLM => ['RAG', 'LLM'],
            BotReply::PIPELINE_ADVISOR => ['Shopify data + RAG', 'LLM'],
            BotReply::PIPELINE_RAG => ['RAG'],
            BotReply::PIPELINE_GUIDE => ['Guide'],
            BotReply::PIPELINE_ASK => ['Question back'],
            default => [],
        };

        return implode(' → ', [$router, ...$after]);
    }

    /**
     * Record how one question was routed. Answers with no specific target fall back to their topic.
     */
    private function logDecision(Message $merchantMessage, int $part, string $question, RoutingDecision $decision, BotReply $reply): void
    {
        $merchantMessage->intentDecisions()->create([
            'part' => $part,
            'question' => $question,
            'final_intent' => $decision->intent,
            'decided_by' => $decision->decidedBy(),
            'target' => $reply->target ?? ($decision->intent === null ? null : "intent:{$decision->intent->value}"),
            'laya_choice' => $decision->layaChoice,
            'laya_confidence' => $decision->layaConfidence,
            'laya_probabilities' => $decision->layaProbabilities === [] ? null : $decision->layaProbabilities,
            'laya_ms' => $decision->layaMs,
            'answered_by' => $reply->answeredBy,
            'search_score' => $reply->scores['search'] ?? null,
            'llm_confidence' => $reply->scores['llm'] ?? null,
            'error' => $decision->error,
        ]);
    }

    /**
     * Keep what this answer showed, so the next message can say "this order", "it" or "their prices".
     * Only the newest of each kind is kept; a New chat starts empty.
     */
    private function remember(Conversation $conversation, BotReply $reply): void
    {
        [$kind, $value] = array_pad(explode(':', (string) $reply->target, 2), 2, null);

        $memory = match ($kind) {
            'order' => ['order' => $value, 'order_summary' => Str::limit($reply->text, 600)],
            'product' => ['products' => [$value]],
            'products' => ['products' => array_values(array_filter(explode('|', (string) $value)))],
            'policy' => ['policy' => $value],
            default => null,
        };

        if ($memory !== null) {
            $conversation->update(['context' => [...($conversation->context ?? []), ...$memory]]);
        }
    }

    private function status(string $stage): void
    {
        if ($this->onStatus !== null) {
            ($this->onStatus)($stage);
        }
    }

    private function emit(string $text): void
    {
        if ($this->onDelta !== null) {
            ($this->onDelta)($text);
        }
    }

    private function timezone(Conversation $conversation): string
    {
        return $conversation->shop->timezone ?? 'UTC';
    }
}
