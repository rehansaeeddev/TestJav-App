<?php

namespace App\Services\Chat;

use App\Services\Shopify\OrderFilter;
use App\Services\Shopify\ProductFilter;
use Carbon\CarbonImmutable;

/**
 * Pulls exact details out of a merchant message with plain rules, so numbers and
 * dates never depend on a model's guess.
 */
class MessageParser
{
    /** The read_orders scope only shows this many days of order history. */
    public const ORDER_HISTORY_DAYS = 60;

    /**
     * An explicit order reference: "#1042", "order 1042", "order number 1042", or a bare "1042".
     */
    public function orderNumber(string $text): ?string
    {
        $patterns = [
            '/#\s*(\d{3,})\b/',
            '/\border\s*(?:number|num|no\.?)?\s*#?\s*(\d{3,})\b/i',
            '/^\s*(\d{3,})\s*$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * Any number of 3+ digits. Only used when the bot just asked for an order number.
     */
    public function anyNumber(string $text): ?string
    {
        return preg_match('/\b(\d{3,})\b/', $text, $match) ? $match[1] : null;
    }

    public function asksForLatestOrder(string $text): bool
    {
        return (bool) preg_match('/\b(last|latest|most recent|newest|recent)\s+order\b/i', $text);
    }

    /**
     * A greeting or thanks with no question in it, e.g. "hello", "thanks!", "ok".
     */
    public function isSmallTalk(string $text): bool
    {
        return (bool) preg_match('/^\s*(hi|hii+|hello|hey|heya|yo|salam|assalam[ou] ?alaikum|good (morning|afternoon|evening)|thanks|thank you|thx|ok|okay|cool|great|bye|goodbye)( there| again| so much)?\s*[!.?]*\s*$/i', $text)
            || $this->asksHowAreYou($text);
    }

    /**
     * "Hey how are you", "i said how are you", "what's up".
     */
    public function asksHowAreYou(string $text): bool
    {
        return (bool) preg_match('/^\s*(i\s+said\s+)?((hi|hey|hello|yo)[\s,!]*)?(how\s+(are|r)\s+(you|u|ya)(\s+doing)?(\s+today)?|how\'?s\s+it\s+going|what\'?s\s+up|wh?at\s+up|sup)\s*[!.?]*\s*$/i', $text);
    }

    /**
     * Questions about the assistant itself: "what are you", "who r u", "what can you do".
     * Allows small typos in "you" ("yoiu", "yuo").
     */
    public function asksAboutAssistant(string $text): bool
    {
        return (bool) preg_match('/^\s*((what|who)\s+(are|r)\s+(y\w{0,3}u|u)|what\s+can\s+(y\w{0,3}u|u)\s+do(\s+for\s+me)?|help|help me)\b[\s!?.]*$/i', $text)
            || (bool) preg_match('/^\s*(can|could|will)\s+(you|u)\s+(manage|run|handle|control|operate|take\s+care\s+of|do\s+everything\s+(in|for))\s+(my\s+|the\s+)?(store|shop|business)\b/i', $text);
    }

    /**
     * Asks about the person, not the store: "what's my name", "who am i".
     */
    public function asksAboutMe(string $text): bool
    {
        return (bool) preg_match('/^\s*(what\'?s|what\s+is|tell\s+me)\s+my\s+(own\s+)?name\s*\??\s*$|^\s*who\s+am\s+i\s*\??\s*$|\bdo\s+you\s+know\s+(me|who\s+i\s+am|my\s+name)\b/i', $text);
    }

    /**
     * Unhappy with the assistant: "why can't you tell me the right answer", "are you insane", "that's wrong".
     */
    public function complainsAboutAssistant(string $text): bool
    {
        return (bool) preg_match('/\b(you|u|your|ur|this\s+answer|that\s+answer|the\s+answer)\b/i', $text)
            && (bool) preg_match('/\b(wrong|right\s+answer|not\s+(right|correct|helpful|working)|useless|stupid|dumb|idiot|insane?|crazy|nonsense|rubbish|terrible|bad\s+(bot|answer)|don\'?t\s+understand|doesn\'?t\s+make\s+sense|not\s+understanding)\b/i', $text);
    }

    /**
     * The thing asked about in "what is finance", "what's the markets page", "explain inventory".
     * Returns null for "what is this page" (that means the current page) and for longer questions.
     */
    public function pageQuestionSubject(string $text): ?string
    {
        /** "What is the Products page used for?", "the purpose of the Discounts page", "I'm on the Analytics page. What am I looking at?" */
        if (! $this->isHowToQuestion($text)
            && preg_match('/\b(what|explain|purpose|used\s+for|looking\s+at|tell\s+me\s+about)\b/i', $text)
            && preg_match('/\b((?:[a-z]+\s+)?[a-z]+)\s+(?:page|section|tab|screen)\b/i', $text, $match)) {
            $words = array_diff(explode(' ', strtolower($match[1])), ['the', 'my', 'this', 'that', 'on', 'of', 'a', 'an', 'in', 'at', 'is', 'what', 'explain', 'purpose', 'about', 'current', 'same']);

            return $words === [] ? null : implode(' ', $words);
        }

        if (! preg_match('/^\s*(?:what\s+(?:is|are|does)|what\'?s|explain|tell\s+me\s+about)\s+(?:the\s+|my\s+|a\s+)?([a-z][a-z ]{1,30}?)(?:\s+(?:page|section|tab|menu|screen))?\s*(?:for|do|mean)?\s*\??\s*$/i', $text, $match)) {
            return null;
        }

        $subject = strtolower(trim($match[1]));

        return in_array($subject, ['this', 'that', 'it', 'this page', 'that page', 'here'], true) ? null : $subject;
    }

    /**
     * The words left after removing page-question words, wherever the page name sits:
     * "Campaign Autopilot what is this page and how do I use it" → "campaign autopilot".
     * Null when only question words remain ("what is this page?").
     */
    public function pageSubjectWords(string $text): ?string
    {
        $noise = [
            'a', 'about', 'am', 'an', 'and', 'are', 'can', 'could', 'do', 'does', 'explain', 'for', 'future', 'here',
            'how', 'i', 'in', 'is', 'it', 'later', 'me', 'mean', 'menu', 'my', 'of', 'on', 'or', 'page', 'perform',
            'screen', 'section', 'should', 'tab', 'task', 'tasks', 'tell', 'that', 'the', 'there', 'thing', 'things',
            'this', 'to', 'use', 'used', 'using', 'we', 'what', 'whats', 'where', 'which', 'why', 'with', 'work', 'you',
        ];

        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower(str_replace("'", '', $text)), $matches);

        $words = collect($matches[0])
            ->reject(fn (string $word) => mb_strlen($word) < 2 || in_array($word, $noise, true))
            ->unique()
            ->take(5);

        return $words->isEmpty() ? null : $words->implode(' ');
    }

    /**
     * Mentions something the store-details answer actually contains.
     */
    public function mentionsStoreDetail(string $text): bool
    {
        return (bool) preg_match('/\b(name|plan|currency|time\s?zone|domain|url|e-?mail|contact|country|address|details|info|information|my\s+(store|shop))\b/i', $text);
    }

    /**
     * "How do I…", "how to…", "where can I…": asking for instructions, not for store data.
     */
    /**
     * Asks what to do about a situation: "What should I do?", "What are my options?", "How should I handle that?".
     */
    public function asksWhatToDo(string $text): bool
    {
        return (bool) preg_match('/\b(what\s+should\s+i\s+do|what\s+are\s+my\s+options|what\s+can\s+i\s+do|how\s+(?:should|do|can)\s+i\s+(?:handle|deal\s+with|respond|fix)|what\s+do\s+i\s+do)\b/i', $text);
    }

    public function isHowToQuestion(string $text): bool
    {
        return (bool) preg_match('/^[\s\p{P}]*(?:(?:(?:can|could)\s+you\s+)?(?:please\s+)?(?:explain|tell\s+me|show\s+me)\s+)?(how\s+(do|can|should|would|could)\s+(i|we|you)\b|how\s+(?:i|we)\s+(?:can|could|should|do)\b|how\s+to\b|where\s+(do|can|should)\s+i\b|what(?:\'s|\s+is|\s+are)\s+the\s+(?:process|steps)\b|(?:can|does|will|could)\s+shopify\b|is\s+there\s+a\s+way\s+to\b)/iu', $text)
            /** "Can I cancel an order after it's fulfilled? How?" */
            || (bool) preg_match('/[?.!]\s*how\s*\?*\s*$/i', $text)
            /** "I need to put 20% off on everything", "I want to add a size chart": a task, not a question about data. */
            || (bool) preg_match('/^[\s\p{P}]*i\s+(need|want|would\s+like|wanna|have)\s+to\s+(?!see|know|check|view|find\s+out|look)\w+/iu', $text)
            /** "I want all my orders in Excel": exporting is a task. */
            || (bool) preg_match('/\b(in|into|to|as)\s+(an?\s+)?(excel|csv|spreadsheet|google\s+sheets?)\b/i', $text);
    }

    public function asksForProductCount(string $text): bool
    {
        return (bool) preg_match('/\bhow\s+many\s+(products|items)\b|\bnumber\s+of\s+products\b|\bproducts?\s+count\b/i', $text);
    }

    /**
     * Asking to see the catalog rather than one product: "give all the product list", "show my products".
     */
    public function asksForProductList(string $text): bool
    {
        if ($this->asksForProductCount($text)) {
            return false;
        }

        return (bool) preg_match('/\b(list|show|bring|give|display|see|view|get)\b[^.?!]*\bproducts\b|\b(all|every)\s+(the\s+|my\s+|of\s+my\s+)?products?\b|\bproducts?\s+list\b|\bcatalog(ue)?\b/i', $text);
    }

    /**
     * Split a message holding several questions so each gets its own answer:
     * "how many orders today and what is my refund policy?" → two questions.
     * "and" only splits when a new question starts after it, so "refund and shipping policy" stays whole.
     *
     * @return non-empty-list<string>
     */
    public function splitQuestions(string $text): array
    {
        $starts = 'how|what|whats|what\'s|which|where|when|who|why|show|list|give|tell|can|could|do|does|is|are|explain|count|change';

        /**
         * A "?" ends a question only when another one follows; trailing notes or "Actually, I meant…" stay attached.
         * "Also" and "then" split only between requests ("…? Also, change…", "…, then show…"), not inside one ("that are also low").
         */
        $pieces = preg_split("/(\?+\s*(?=[\"“']?(?:{$starts})\b)|\n+|;\s*|(?:[?.!,]\s*|\s+and\s+)also,?\s+|\s+also,?\s+(?=(?:{$starts})\b)|,?\s+then\s+(?=(?:{$starts})\b)|\s+and\s+(?=(?:{$starts})\b)|,\s*(?=(?:and\s+)?(?:{$starts})\b))/iu", $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $questions = [];
        $current = (string) array_shift($pieces);

        while ($pieces !== []) {
            $delimiter = (string) array_shift($pieces);
            $next = (string) array_shift($pieces);

            /** "Can I cancel it after it's fulfilled? How?": a one-word tail belongs to the question before it. */
            if (str_word_count($next) < 2) {
                $current .= $delimiter.$next;

                continue;
            }

            /** "My printer is ready, how do I…" is one question: a comma or "and" splits only after a question or command. */
            $softDelimiter = (bool) preg_match('/^(,|\s+and\s+)/i', $delimiter);

            /**
             * "Check order 1057 and tell me what it contains": a part pointing back with "it" belongs to the one before,
             * unless it asks something new about it ("…and explain how to refund it", "…, and which collections have them").
             */
            $nextAsksSomethingNew = (bool) preg_match('/^(?:and\s+)?(how|what|whats|which|where|when|why|explain)\b/i', $next);

            if ($softDelimiter && (! $this->looksLikeQuestion($current) || (preg_match('/\b(it|its|that|them|this|those)\b/i', $next) && ! $nextAsksSomethingNew))) {
                $current .= $delimiter.$next;

                continue;
            }

            $questions[] = $current;
            $current = $next;
        }

        $questions[] = $current;

        $questions = collect($questions)
            ->map(fn (string $part, int $index) => (string) preg_replace($index > 0 ? '/^and\s+/i' : '/^$/', '', trim($part, " \t.,!?\"“”'")))
            ->filter(fn (string $part) => str_word_count($part) >= 2)
            ->take(3)
            ->values()
            ->all();

        return $questions === [] ? [trim($text, " \t\"“”'")] : $questions;
    }

    /**
     * Starts like a question or a request ("how many…", "status of 1055", "show me…").
     */
    public function looksLikeQuestion(string $text): bool
    {
        return (bool) preg_match('/^[\s\p{P}]*(how|what|whats|which|where|when|who|why|show|list|give|tell|can|could|do|does|did|is|are|was|were|has|have|explain|find|check|look|count|status|pull|search|open|compare|get|any)\b/iu', $text);
    }

    /**
     * "Find order 1055? Actually, I meant 1056" → "Find order 1056".
     * "How many orders last month? I mean this month." → "How many orders this month".
     * The corrected value replaces the one it corrects; without one, the correction is the question.
     */
    public function applyCorrection(string $text): string
    {
        if (! preg_match('/^(.*?)[\s,.?!]*\b(?:actually|sorry|no|oops)?[\s,]*\bi\s+mean[t]?\b[\s,:]*(.+)$/isu', $text, $match) || trim($match[1]) === '') {
            return $text;
        }

        [$before, $fix] = [trim($match[1], " \t?.!,"), trim($match[2], " \t?.!,")];
        $period = '/\b(today|yesterday|(?:this|last|previous|past)\s+(?:week|month|year)|(?:last|past)\s+\d+\s+days?)\b/i';

        if (preg_match('/\d{3,}/', $fix, $number) && preg_match('/\d{3,}/', $before)) {
            return (string) preg_replace('/\d{3,}(?!.*\d{3,})/s', $number[0], $before);
        }

        if (preg_match($period, $fix, $newPeriod) && preg_match($period, $before)) {
            return (string) preg_replace($period, $newPeriod[0], $before, 1);
        }

        return str_word_count($fix) >= 2 ? $fix : $before;
    }

    public function mentionsDiscountsOrCollections(string $text): bool
    {
        return (bool) preg_match('/\b(discounts?|coupons?|promo|promotions?|codes?|sales?|offers?|deals?|free\s+shipping|collections?)\b|%\s*off\b/i', $text)
            || (bool) preg_match('/\b(?=[A-Z0-9]*[A-Z])(?=[A-Z0-9]*\d)[A-Z0-9]{4,}\b/', $text);
    }

    /**
     * Wants the last answer said differently: "explain in simple wording", "simpler please", "what do you mean".
     */
    public function asksToRephrase(string $text): bool
    {
        return (bool) preg_match('/\b(simple|simpler|simply|easier|easy\s+words|plain\s+(english|words)|in\s+other\s+words|rephrase|explain\s+(again|more|better)|more\s+detail|elaborate|what\s+do\s+you\s+mean|didn\'?t\s+(get|understand)\s+(it|that|this)?|eli5)\b/i', $text);
    }

    /**
     * Points back at what was just discussed: "the option here says…", "what does that button do",
     * "after that", "I don't see it". Used to keep a follow-up on the guide we last showed.
     */
    public function refersToPrevious(string $text): bool
    {
        if ($this->asksToRephrase($text)) {
            return true;
        }

        return (bool) preg_match('/\b(here|there|this|that|it|option|options|button|step|steps|says|saying|shows|showing|see|seeing|instead|next|then|after|before|same)\b/i', $text);
    }

    /**
     * "Which product should I buy for my inventory?", "what should I reorder", "what to restock".
     */
    public function asksWhatToRestock(string $text): bool
    {
        return (bool) preg_match('/\b(which|what)\s+(products?\s+|items?\s+|stock\s+)?((should|do|to|must)\s+)?(i|we)?\s*(should\s+|need\s+to\s+)?(buy|order|re-?order|re-?stock|stock\s+up(\s+on)?|purchase|get\s+more\s+of)\b|\b(re-?order|re-?stock)\s+(suggestions?|list|advice|plan)\b/i', $text);
    }

    /**
     * "What are my best sellers?", "top products", "what sells the most".
     */
    public function asksForBestSellers(string $text): bool
    {
        return (bool) preg_match('/\b(best[\s-]?sell(?:ers?|ing)|top[\s-]?(?:selling|sellers?|products?|items?)|most\s+(?:sold|popular|ordered|selling|bought)|sells?\s+(?:the\s+)?(?:most|best)|selling\s+(?:the\s+)?(?:most|best)|popular\s+(?:products?|items?)|best\s+(?:products?|items?))\b/i', $text);
    }

    /**
     * Wants advice to grow the business: "how to increase my sales", "get more customers",
     * "why are my sales down", "marketing ideas". Reading a sales report is not advice.
     */
    public function asksForGrowthAdvice(string $text): bool
    {
        $grow = '(increase|increased|increasing|grow|growing|boost|improve|raise|double|get\s+more|bring\s+(?:in\s+)?more|drive\s+more)';
        $what = '(sales|sale|revenue|orders|customers|buyers|traffic|visitors|conversions?|conversion\s+rate|business|profit|income|money)';

        return (bool) preg_match("/\b{$grow}\b(?:\s+\w+){0,3}\s+{$what}\b|\b(sell\s+more|marketing\s+(ideas|tips|strategy|plan)|grow\s+my\s+(store|shop|brand)|why\s+(?:are|is)\s+(?:my\s+)?(?:sales|orders)\s+(?:low|down|dropping|slow)|(?:no|low|not\s+getting\s+(?:any\s+)?)\s*(?:sales|orders))\b/i", $text);
    }

    /**
     * Which orders the merchant means: "unfulfilled", "paid but not shipped", "unpaid orders over $100",
     * "orders that used WELCOME10", "high-risk orders", "excluding cancelled ones", "my biggest orders".
     */
    public function orderFilter(string $text): OrderFilter
    {
        $notMoney = '(?!\s*(?:days?|weeks?|months?|years?|hours?|orders?|items?|units?|products?|pieces?|%))';
        $amount = '(?:\$|usd\s*|rs\.?\s*|pkr\s*)?(\d++(?:\.\d++)?)'.$notMoney;

        $fulfillment = match (true) {
            (bool) preg_match('/\bpartial(ly)?\s+(fulfill?ed|shipped)\b/i', $text) => 'partial',
            (bool) preg_match('/\b(un-?fulfill?ed|not\s+(yet\s+)?(fulfill?ed|shipped|sent)|unshipped|(haven\'?t|have\s+not|hasn\'?t|has\s+not)\s+(been\s+)?(shipped|fulfill?ed|sent)|to\s+(ship|fulfill?)|pending\s+(shipment|fulfill?ment))\b/i', $text) => 'unfulfilled',
            (bool) preg_match('/\b(fulfill?ed|shipped)\b/i', $text) => 'fulfilled',
            default => null,
        };

        $payment = match (true) {
            (bool) preg_match('/\b(unpaid|not\s+(yet\s+)?paid|pending\s+payment|payment\s+pending|awaiting\s+payment)\b/i', $text) => 'pending',
            (bool) preg_match('/\brefunded\b/i', $text) => 'refunded',
            (bool) preg_match('/\bpaid\b/i', $text) => 'paid',
            default => null,
        };

        $cancelled = match (true) {
            (bool) preg_match('/\b(exclud\w*|without|except|not|no|non)[\s-]+(the\s+)?cancell?ed\b/i', $text) => false,
            (bool) preg_match('/\bcancell?ed\b/i', $text) => true,
            default => null,
        };

        preg_match('/\b(?:over|above|more\s+than|greater\s+than|bigger\s+than|at\s+least)\s+'.$amount.'/i', $text, $over);
        preg_match('/\b(?:under|below|less\s+than|smaller\s+than|cheaper\s+than)\s+'.$amount.'/i', $text, $under);

        /** Only letters, digits, "-" and "_" survive, so a code can't change the meaning of Shopify's search. */
        $code = preg_match('/\b(?:discount|coupon|promo)?\s*code\s+([A-Za-z0-9_-]{3,})\b|\b(?:used|using|with)\s+(?:the\s+)?([A-Z][A-Z0-9_-]{3,})\b/', $text, $codeMatch)
            ? ($codeMatch[1] ?: $codeMatch[2])
            : null;

        $sort = match (true) {
            (bool) preg_match('/\b(biggest|largest|highest|most\s+expensive|top)\s+(\d+\s+)?(orders?|sales?)\b/i', $text) => 'largest',
            (bool) preg_match('/\b(smallest|lowest|cheapest)\s+(\d+\s+)?orders?\b/i', $text) => 'smallest',
            default => null,
        };

        return new OrderFilter(
            fulfillment: $fulfillment,
            payment: $payment,
            cancelled: $cancelled,
            minTotal: isset($over[1]) ? (float) $over[1] : null,
            maxTotal: isset($under[1]) ? (float) $under[1] : null,
            discountCode: $code !== null ? strtoupper($code) : null,
            highRisk: (bool) preg_match('/\b(high[\s-]?risk|risky|fraud(ulent)?|suspicious)\b/i', $text),
            sort: $sort,
        );
    }

    /**
     * Which products the merchant means: "under $25", "over 100", "out of stock", "in stock", "draft",
     * "under $25 that are low in stock". Plain "low stock" alone is left to the per-variant low-stock answer.
     */
    public function productFilter(string $text): ProductFilter
    {
        $amount = '(?:\$|usd\s*|rs\.?\s*|pkr\s*)?(\d++(?:\.\d++)?)(?!\s*(?:days?|weeks?|months?|years?|hours?|orders?|items?|units?|pieces?|in\s+stock|left|%))';

        preg_match('/\b(?:over|above|more\s+than|greater\s+than|at\s+least|pricier\s+than)\s+'.$amount.'/i', $text, $over);
        preg_match('/\b(?:under|below|less\s+than|cheaper\s+than)\s+'.$amount.'/i', $text, $under);

        $status = preg_match('/\b(draft|archived|active)\b/i', $text, $statusMatch) ? strtolower($statusMatch[1]) : null;

        /** "Fewer than three units left", "less than 5 in stock": a stock count, not a price. */
        $fewerThan = preg_match('/\b(?:less|fewer)\s+than\s+(\d+)\s+(?:units?\s+|pieces?\s+|items?\s+)?(?:left|in\s+stock|remaining)\b|\bunder\s+(\d+)\s+(?:units?\s+)?(?:left|in\s+stock)\b/i', $this->numbersAsDigits(mb_strtolower($text), 'units?|pieces?|items?|left|in\s+stock|remaining'), $quantity)
            ? (int) ($quantity[1] ?: $quantity[2])
            : null;
        $narrowed = isset($over[1]) || isset($under[1]) || $status !== null;

        $stock = match (true) {
            /** Plain "what's out of stock" stays with the per-size answer, which also sees one sold-out size. */
            $narrowed && (bool) preg_match('/\b(out\s+of\s+stock|no\s+stock|sold\s+out|zero\s+stock|with\s+0\s+stock)\b/i', $text) => 'out',
            $fewerThan !== null, $narrowed && $this->asksForLowStock($text) => 'low',
            (bool) preg_match('/\b(in\s+stock|have\s+stock|available\s+to\s+sell)\b/i', $text) => 'in',
            default => null,
        };

        return new ProductFilter(
            minPrice: isset($over[1]) ? (float) $over[1] : null,
            maxPrice: isset($under[1]) ? (float) $under[1] : null,
            stock: $stock,
            status: $status,
            fewerThan: $fewerThan,
        );
    }

    /**
     * Points at the period before the one just discussed: "the day before", "the week before that", "previous month".
     */
    public function asksForPreviousPeriod(string $text): bool
    {
        return (bool) preg_match('/\b(day|week|month)\s+before\b|\b(previous|prior|preceding)\s+(day|week|month|period|one)\b|\bbefore\s+that\b/i', $text);
    }

    /**
     * Asks how much money came in: "how much did I earn this year", "revenue last week", "how much did we make".
     */
    public function asksForRevenue(string $text): bool
    {
        return (bool) preg_match('/\b(revenue|earnings?|income|turnover|gross\s+sales|total\s+sales|sales\s+total)\b|\bearn(s|ed|ing)?\b|\bhow\s+much\b.*\b(make|made|money)\b|\bhow\s+much\s+(in\s+)?sales\b/i', $text);
    }

    /**
     * Asks for secrets or other people's private data: passwords, card numbers, API keys, every customer's address.
     */
    public function asksForPrivateData(string $text): bool
    {
        return (bool) preg_match('/\b(credit\s*card(\s+numbers?)?|card\s+numbers?|cvv|cvc|security\s+codes?|api\s*keys?|secret\s*keys?|access\s+tokens?|secrets?)\b/i', $text)
            || (bool) preg_match('/\b(customers?|users?|staff|members?|account|login|admin|their|his|her)\'?s?\s+passwords?\b|\bpasswords?\s+(of|for)\s+(my\s+|the\s+|all\s+|every\s+)?(customers?|users?|staff|accounts?)\b/i', $text)
            || (bool) preg_match('/\b(all|every|each)\s+customers?\'?s?\b.*\b(address(es)?|phone(\s+numbers?)?|e-?mails?|personal|details|data)\b|\bcustomers?\'?s?\s+(home\s+)?(address(es)?|phone\s+numbers?)\b/i', $text);
    }

    /**
     * Asks to know orders or sales before they happen: "how many orders tomorrow", "what will my sales be next month".
     */
    public function asksAboutFuture(string $text): bool
    {
        return (bool) preg_match('/\b(orders?|sales?|sell|revenue|customers?)\b/i', $text)
            && (bool) preg_match('/\b(tomorrow|next\s+(week|month|year|quarter)|forecast|predict(ion)?|projection|will\s+(i|we|my|there|it)\b.*\b(be|get|make|sell|arrive|come))\b/i', $text);
    }

    /**
     * Plainly nothing to do with running the store: the weather, jokes, recipes.
     */
    public function isOffTopic(string $text): bool
    {
        return (bool) preg_match('/\b(weather|temperature\s+outside|recipes?|jokes?|lyrics|poems?|horoscope)\b/i', $text);
    }

    /**
     * Names something to do in the admin: "hide a product without deleting it", "upload products from a spreadsheet".
     */
    public function namesTask(string $text): bool
    {
        return (bool) preg_match('/\b(hide|unhide|delete|remove|add|upload|import|export|edit|change|create|set\s+up|archive|duplicate|publish|unpublish|transfer|bulk)\b/i', $text);
    }

    /**
     * A short follow-up that only makes sense with the last answer: "and my timezone?", "what about the day before?",
     * "only the ones in stock", "exclude cancelled ones", "does that apply internationally?".
     */
    public function continuesTopic(string $text): bool
    {
        return str_word_count($text) <= 10
            && (bool) preg_match('/^[\s\p{P}]*(and|also|but|no|i\s+(told\s+you|said|asked(\s+for)?|meant)|what\s+about|how\s+about|only|just\s+the|exclude|excluding|except|without|but\s+only|(does|do|is|are|did|was|will)\s+(that|it|this|they|those|these)|what\s+if|same\s+for)\b/iu', $text);
    }

    /**
     * A law or compliance question: "do I need a cookie banner for GDPR?", "is it legal to sell CBD?".
     * Answered only from guides, with a fixed disclaimer; never a yes/no verdict from the model.
     */
    public function asksLegalQuestion(string $text): bool
    {
        return (bool) preg_match('/\b(legal(ly)?|illegal|laws?|lawful|unlawful|lawyer|attorney|gdpr|ccpa|cpra|pci(\s*dss)?|compliance|compliant|comply|regulations?|regulatory|liab(le|ility)|data\s+protection|consumer\s+rights?|right\s+to\s+(be\s+forgotten|erasure)|(allowed|permitted)\s+to\s+sell|can\s+i\s+(legally\s+)?sell|prohibited|restricted\s+(items?|products?)|vat\s+registration|register\s+for\s+(vat|gst|sales\s+tax)|personal\s+(data|information)|consent|do\s+not\s+sell|ada|wcag|(pay|file|remit)\s+(my\s+)?(sales\s+)?tax(es)?|accepts?\s+marketing|marketing\s+(box|checkbox|consent)|double\s+opt[\s-]?in)\b/i', $text);
    }

    /**
     * Asks the assistant to change something in the store: "delete all my orders", "create a new order".
     * The app is read-only, so these get an explanation of how to do it instead.
     */
    public function asksToDoAction(string $text): bool
    {
        return (bool) preg_match('/^[\s\p{P}]*(?:(?:can|could|would|will)\s+you\s+|please\s+|i\s+want\s+(?:you\s+)?to\s+|go\s+ahead\s+and\s+)?(?:please\s+)?(delete|remove|create|make|add|cancel|refund|edit|change|update|archive|unarchive|fulfill?|ship|mark|duplicate|hide|publish|unpublish|import|export|restock|discount|email|e-mail|message|notify|contact|send\s+(?:an?\s+)?(?:email|message|notification))\b/iu', $text);
    }

    /**
     * Points at an order already in the conversation: "this order", "that one", "is it paid?".
     */
    public function pointsAtOrder(string $text): bool
    {
        return (bool) preg_match('/\b(?:this|that|the\s+same|same|the|its)\s+order\b|\b(?:it|its|this\s+one|that\s+one)\b/i', $text);
    }

    /**
     * Asks about an order's state: paid, shipped, delivered, tracking, what's in it.
     */
    public function asksOrderStatus(string $text): bool
    {
        return (bool) preg_match('/\b(status|paid|payment|shipped|ship|delivered|delivery|arrived|fulfill?ed|fulfill?ment|tracking|refunded|cancell?ed|contains?|items|total|processing)\b/i', $text);
    }

    /**
     * Points at products already in the conversation: "their prices", "those", "them".
     */
    public function pointsAtProducts(string $text): bool
    {
        return (bool) preg_match('/\b(their|them|those|these|they|this\s+product|that\s+product|these\s+products|those\s+products)\b/i', $text);
    }

    public function mentionsProducts(string $text): bool
    {
        return (bool) preg_match('/\b(products?|items?|stock|inventory|price|prices|priced|cost|cheap|cheaper|cheapest|expensive|variants?|sizes?|colou?rs?|sku|units|available|availability|sell|selling)\b/i', $text);
    }

    public function asksForLowStock(string $text): bool
    {
        return (bool) preg_match('/\b(low\s+((on|in)\s+)?stock|low\s+inventory|out\s+of\s+stock|running\s+(low|out)|sold\s+out|need\s+to\s+(restock|reorder))\b/i', $text);
    }

    public function asksAboutCollections(string $text): bool
    {
        return (bool) preg_match('/\bcollections?\b/i', $text);
    }

    /**
     * The product name words left after removing question words, e.g.
     * "how much stock of the denim jacket do I have?" → "denim jacket".
     */
    public function productSearchTerms(string $text): ?string
    {
        $noise = [
            'a', 'an', 'and', 'any', 'are', 'about', 'available', 'availability', 'color', 'colors', 'cost', 'costs',
            'do', 'does', 'for', 'have', 'how', 'i', 'in', 'is', 'it', 'item', 'items', 'left', 'many', 'me', 'much',
            'my', 'of', 'on', 'price', 'prices', 'product', 'products', 'qty', 'quantity', 'sell', 'selling', 'show',
            'size', 'sizes', 'stock', 'tell', 'the', 'there', 'this', 'units', 'variant', 'variants', 'we', 'what',
            'whats', 'which', 'inventory', 'level', 'levels', 'still', 'got', 'please', 'can', 'you', 'check', 'called', 'named',
        ];

        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower(str_replace("'", '', $text)), $matches);

        $words = collect($matches[0])
            ->reject(fn (string $word) => mb_strlen($word) < 2 || in_array($word, $noise, true))
            ->unique()
            ->take(5);

        return $words->isEmpty() ? null : $words->implode(' ');
    }

    /**
     * Which store policy the merchant means, as Shopify's ShopPolicyType value.
     */
    public function policyType(string $text): ?string
    {
        $text = $this->fixPolicyTypos(mb_strtolower($text));

        return match (true) {
            str_contains($text, 'terms of sale') => 'TERMS_OF_SALE',
            (bool) preg_match('/\b(refunds?|returns?|exchanges?)\b/', $text) => 'REFUND_POLICY',
            (bool) preg_match('/\b(ship|ships|shipped|shipping|deliver|delivers|delivered|delivery)\b/', $text) => 'SHIPPING_POLICY',
            (bool) preg_match('/\bprivacy\b/', $text) => 'PRIVACY_POLICY',
            (bool) preg_match('/\b(terms|tos)\b/', $text) => 'TERMS_OF_SERVICE',
            (bool) preg_match('/\bsubscriptions?\b/', $text) => 'SUBSCRIPTION_POLICY',
            (bool) preg_match('/\b(legal notice|imprint)\b/', $text) => 'LEGAL_NOTICE',
            (bool) preg_match('/\bcontact\b/', $text) => 'CONTACT_INFORMATION',
            default => null,
        };
    }

    /**
     * "refnd polcy" → "refund policy": one wrong, missing or extra letter in a policy word.
     */
    private function fixPolicyTypos(string $text): string
    {
        $words = ['refund', 'return', 'returns', 'shipping', 'privacy', 'policy', 'exchange'];

        return (string) preg_replace_callback('/\b[a-z]{5,}\b/', function (array $match) use ($words): string {
            foreach ($words as $word) {
                if ($match[0] !== $word && levenshtein($match[0], $word) === 1) {
                    return $word;
                }
            }

            return $match[0];
        }, $text);
    }

    public function mentionsPolicy(string $text): bool
    {
        return $this->policyType($text) !== null || (bool) preg_match('/\bpolic(y|ies)\b/i', $this->fixPolicyTypos(mb_strtolower($text)));
    }

    /**
     * A supported time period, in the shop's timezone. "to" is exclusive.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}|null
     */
    public function dateRange(string $text, string $timezone): ?array
    {
        $text = $this->numbersAsDigits(mb_strtolower($text));

        /** "…previous calendar month, not the last 30 days": the period being ruled out must not be picked. */
        $text = (string) preg_replace('/\b(?:not|rather than|instead of)\s+(?:the\s+)?(?:last|past|previous)\s+\d+\s+\w+/', '', $text);

        /** "previous month", "past week", "prior calendar year" mean the same as "last …". */
        $text = (string) preg_replace('/\b(previous|past|prior|last)\s+(?:calendar\s+)?(week|month|year)\b/', 'last $2', $text);
        $text = (string) preg_replace('/\b(previous|prior)\s+day\b/', 'yesterday', $text);
        $text = (string) preg_replace('/\b(since\s+midnight|this\s+morning|so\s+far\s+today|earlier\s+today)\b/', 'today', $text);

        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $tomorrow = $today->addDay();
        $thisMonth = $today->startOfMonth();

        if (preg_match('/\bmore\s+than\s+(\d{1,3})\s+days?\s+ago\b|\bolder\s+than\s+(\d{1,3})\s+days?\b/', $text, $match)) {
            $days = (int) ($match[1] ?: $match[2]);

            return ['from' => $today->subYears(20), 'to' => $today->subDays($days), 'label' => "more than {$days} days ago"];
        }

        /** "180 days ago", "3 weeks ago": that one day or week. */
        if (preg_match('/\b(\d{1,3})\s+(days?|weeks?)\s+ago\b/', $text, $match)) {
            $isWeek = str_starts_with($match[2], 'week');
            $from = $isWeek ? $today->subWeeks((int) $match[1])->startOfWeek() : $today->subDays((int) $match[1]);

            return ['from' => $from, 'to' => $isWeek ? $from->addWeek() : $from->addDay(), 'label' => "{$match[1]} {$match[2]} ago"];
        }

        if (preg_match('/\b(?:last|past)\s+(\d{1,2})\s+hours?\b/', $text, $match)) {
            $hours = max(1, (int) $match[1]);

            return ['from' => $now->subHours($hours), 'to' => $now->addMinute(), 'label' => "in the last {$hours} hours"];
        }

        if (preg_match('/\b(?:last|past)\s+(\d{1,3})\s+(days?|weeks?|months?)\b/', $text, $match)) {
            $amount = max(1, (int) $match[1]);
            $unit = rtrim($match[2], 's');
            $from = match ($unit) {
                'day' => $today->subDays($amount - 1),
                'week' => $today->subDays($amount * 7 - 1),
                'month' => $today->subMonths($amount)->addDay(),
            };

            return ['from' => $from, 'to' => $tomorrow, 'label' => "in the last {$amount} {$unit}".($amount === 1 ? '' : 's')];
        }

        if (($span = $this->monthDaySpan($text, $today)) !== null) {
            return $span;
        }

        if (($month = $this->namedMonth($text, $today)) !== null) {
            return $month;
        }

        return match (true) {
            str_contains($text, 'yesterday') => [
                'from' => $today->subDay(), 'to' => $today, 'label' => 'yesterday ('.$today->subDay()->format('M j').')',
            ],
            str_contains($text, 'today') => [
                'from' => $today, 'to' => $tomorrow, 'label' => 'today ('.$today->format('M j').')',
            ],
            str_contains($text, 'last week') => [
                'from' => $today->startOfWeek()->subWeek(), 'to' => $today->startOfWeek(), 'label' => 'last week',
            ],
            str_contains($text, 'this week') => [
                'from' => $today->startOfWeek(), 'to' => $tomorrow, 'label' => 'this week',
            ],
            str_contains($text, 'last month') => [
                'from' => $thisMonth->subMonth(), 'to' => $thisMonth, 'label' => 'last month ('.$thisMonth->subMonth()->format('F').')',
            ],
            str_contains($text, 'this month') => [
                'from' => $thisMonth, 'to' => $tomorrow, 'label' => 'this month',
            ],
            str_contains($text, 'last year') => [
                'from' => $today->startOfYear()->subYear(), 'to' => $today->startOfYear(), 'label' => 'last year ('.($today->year - 1).')',
            ],
            str_contains($text, 'this year') => [
                'from' => $today->startOfYear(), 'to' => $tomorrow, 'label' => 'this year',
            ],
            (bool) preg_match('/\b(all|every|ever|so far|till now|until now|all[- ]time|any ?time)\b/', $text) => [
                'from' => $today->subDays(self::ORDER_HISTORY_DAYS - 1),
                'to' => $tomorrow,
                'label' => 'in the last '.self::ORDER_HISTORY_DAYS.' days (the oldest orders this app can see)',
            ],
            default => null,
        };
    }

    /**
     * Each period named in the message, in order: "this week's count vs last week's" → two.
     *
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable, label: string}>
     */
    public function dateRanges(string $text, string $timezone): array
    {
        return collect(preg_split('/\b(?:with|vs\.?|versus|than|and|or|compared\s+to)\b/i', $text))
            ->map(fn (string $part) => $this->dateRange($part, $timezone))
            ->filter()
            ->unique('label')
            ->values()
            ->all();
    }

    /**
     * "seven days" → "7 days", so spelled-out amounts work like digits.
     */
    private function numbersAsDigits(string $text, string $followedBy = 'hours?|days?|weeks?|months?'): string
    {
        $numbers = [
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8,
            'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'fourteen' => 14, 'fifteen' => 15,
            'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'forty-five' => 45, 'sixty' => 60, 'ninety' => 90,
        ];

        return (string) preg_replace_callback('/\b('.implode('|', array_keys($numbers)).')\b(?=\s+(?:'.$followedBy.')\b)/',
            fn (array $match) => (string) $numbers[$match[1]], $text);
    }

    /**
     * "September 1 through September 15", "sep 1 - 15", "from oct 3 to oct 10". A span without a
     * year means the most recent one that has started.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}|null
     */
    private function monthDaySpan(string $text, CarbonImmutable $today): ?array
    {
        $months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

        if (! preg_match("/\b({$months})\s+(\d{1,2})(?:st|nd|rd|th)?\s*(?:through|thru|to|until|till|-|–|and)\s*(?:({$months})\s+)?(\d{1,2})(?:st|nd|rd|th)?\b/", $text, $match)) {
            return null;
        }

        $from = CarbonImmutable::parse("{$match[1]} {$match[2]} {$today->year}", $today->timezone)->startOfDay();
        $to = CarbonImmutable::parse(($match[3] ?: $match[1])." {$match[4]} {$today->year}", $today->timezone)->startOfDay();

        if ($from->greaterThan($today)) {
            [$from, $to] = [$from->subYear(), $to->subYear()];
        }

        if ($to->lessThan($from)) {
            return null;
        }

        return ['from' => $from, 'to' => $to->addDay(), 'label' => 'from '.$from->format('M j').' to '.$to->format('M j, Y')];
    }

    /**
     * "in September", "september 2025", "for may". A month without a year means the most recent one.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string}|null
     */
    private function namedMonth(string $text, CarbonImmutable $today): ?array
    {
        $months = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
            'august' => 8, 'september' => 9, 'sept' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        ];

        if (! preg_match('/\b(?:(in|of|for|during)\s+)?('.implode('|', array_keys($months)).')(?:\s+(\d{4}))?\b/', $text, $match)) {
            return null;
        }

        $preposition = $match[1] ?? '';
        $year = $match[3] ?? '';

        /** Bare "may" is usually the verb ("may I see…"). */
        if ($match[2] === 'may' && $preposition === '' && $year === '') {
            return null;
        }

        $from = $today->setDate($year !== '' ? (int) $year : $today->year, $months[$match[2]], 1);

        if ($year === '' && $from->greaterThan($today)) {
            $from = $from->subYear();
        }

        return ['from' => $from, 'to' => $from->addMonth(), 'label' => 'in '.$from->format('F Y')];
    }

    /**
     * Asking to see orders rather than count them: "list my orders", "bring me all order list".
     */
    public function asksForOrderList(string $text): bool
    {
        /** "all my orders in excel" or "access to orders" is a task, not a request to see the list. */
        if (preg_match('/\b(how\s+many|count|number\s+of|excel|csv|spreadsheet|export|download|print|access|permissions?|employees?|staff|archive|cancel|refund|edit|delete)\b/i', $text)) {
            return false;
        }

        return (bool) preg_match('/\b(list|show|bring|give|display|see|view|get)\b[^.?!]*\borders?\b|\b(all|recent|latest)\s+(the\s+|my\s+)?orders\b|\border\s+list\b/i', $text);
    }
}
