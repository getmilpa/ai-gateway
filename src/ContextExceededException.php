<?php

/**
 * This file is part of Milpa AI Gateway — the dual-provider LLM client and agentic
 * tool-use runtime for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/**
 * The provider answered the ONE 4xx a budgeted agent leg can govern: an HTTP 400 whose body
 * carries `"type":"exceed_context_size_error"` — the OpenAI-compat exceed-context error,
 * measured verbatim on greenhouse fixture run 12 («request (33246 tokens) exceeds the
 * available context size (32768)») one step from the first full completion.
 *
 * It extends `RuntimeException` and keeps the exact `"$provider API Error: HTTP 400 - ..."`
 * message the untyped path produced, so every consumer that catches the base class sees zero
 * change; the type exists so {@see AgentOrchestrator} — the owner of re-projection — can
 * distinguish this failure and heal it: shrink the leg budget by the provider's own measured
 * overage, re-project from full history, retry. Every OTHER 4xx stays the plain
 * `RuntimeException` it always was, surfacing verbatim un-retried.
 *
 * The provider's numbers ride along when the body carried them: `n_prompt_tokens` (what the
 * provider counted in the rejected request) and `n_ctx` (its window). `null` means «not
 * said», never a fabricated zero — the healer falls back conservatively.
 */
final class ContextExceededException extends \RuntimeException
{
    /**
     * @param string   $message       The same `"$provider API Error: HTTP 400 - ..."` line the
     *                                untyped path would have thrown, verbatim.
     * @param int|null $nPromptTokens The provider's own count of the rejected request, when the
     *                                body named it.
     * @param int|null $nCtx          The provider's declared context window, when the body
     *                                named it.
     */
    public function __construct(
        string $message,
        public readonly ?int $nPromptTokens = null,
        public readonly ?int $nCtx = null,
    ) {
        parent::__construct($message);
    }
}
