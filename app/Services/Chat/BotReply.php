<?php

namespace App\Services\Chat;

/**
 * What the bot answers: the text, plus an optional admin page link and official guide.
 * Links are built by code, never written by the model.
 *
 * $target says what the answer was about ("guide:cancel-order", "order:1042", "ask:OrderNumber"),
 * so the decision log and the eval can check the right thing was answered, not just the topic.
 *
 * $pipeline says which parts produced the answer after routing (see answeredBy()), and $scores
 * how sure the guide search and the LLM were. Laya's confidence is added by the orchestrator.
 */
final readonly class BotReply
{
    public const PIPELINE_DATA = 'data';

    public const PIPELINE_DATA_LLM = 'data_llm';

    public const PIPELINE_RAG_LLM = 'rag_llm';

    public const PIPELINE_RAG = 'rag';

    public const PIPELINE_GUIDE = 'guide';

    public const PIPELINE_ASK = 'ask';

    public const PIPELINE_NONE = 'none';

    /** Store numbers + growth guides (RAG), written up by the LLM as advice. */
    public const PIPELINE_ADVISOR = 'advisor';

    /**
     * @param  array{search?: float|null, llm?: float|null}  $scores
     * @param  array<string, float>  $confidence  Shown to the merchant: laya, search, llm (0–1).
     */
    public function __construct(
        public string $text,
        public ?string $adminPath = null,
        public ?string $linkLabel = null,
        public ?string $helpUrl = null,
        public ?string $target = null,
        public string $pipeline = self::PIPELINE_DATA,
        public array $scores = [],
        public ?string $answeredBy = null,
        public array $confidence = [],
    ) {}

    /**
     * The same reply with a fixed sentence put first, e.g. "I can't make changes in your store".
     */
    public function withIntro(string $intro): self
    {
        return new self($intro."\n\n".trim($this->text), $this->adminPath, $this->linkLabel, $this->helpUrl, $this->target,
            $this->pipeline, $this->scores, $this->answeredBy, $this->confidence);
    }

    /**
     * The same reply with a fixed note added at the end, e.g. the legal disclaimer.
     */
    public function withNote(string $note): self
    {
        return new self(trim($this->text)."\n\n".$note, $this->adminPath, $this->linkLabel, $this->helpUrl, $this->target,
            $this->pipeline, $this->scores, $this->answeredBy, $this->confidence);
    }

    /**
     * The same reply, labelled with who answered and how sure each part was.
     *
     * @param  array<string, float>  $confidence
     */
    public function labelled(string $answeredBy, array $confidence): self
    {
        return new self($this->text, $this->adminPath, $this->linkLabel, $this->helpUrl, $this->target,
            $this->pipeline, $this->scores, $answeredBy, $confidence);
    }
}
