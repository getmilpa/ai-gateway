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
 * Sees what one model call REASONED, the moment its response is decoded.
 *
 * A reasoning model answers on two channels: the {@see ChannelObserver} request is what the agent was
 * GIVEN, the answer's `content`/`tool_calls` are what the agent DID, and this is a THIRD fact neither
 * carries — the private deliberation the provider exposes as a separate `reasoning_content`, distinct
 * from the reply it precedes. It is not cost ({@see ReturnObserver}) and it is not the reply; folding
 * it into either would make that seam report two facts as one. So it gets its own seam.
 *
 * Like the other return-side seams this is a SEPARATE, OPTIONAL interface: the gateway reports
 * reasoning only to an observer that opts in (`instanceof ReasoningObserver`), and only when the
 * provider actually spoke it — a model that reasons silently produces no call here, never an empty
 * one. Implementations MUST NOT throw and MUST NOT be slow: observing a channel may not change it.
 */
interface ReasoningObserver
{
    /**
     * Hands over the reasoning of one call, once its body is decoded on success.
     *
     * @param string $uri       The endpoint the call went to — the same value its matching
     *                          {@see ChannelObserver::observe()} received.
     * @param string $reasoning The provider's `reasoning_content` for this call, verbatim and
     *                          non-empty. Absent or empty reasoning fires no call at all.
     */
    public function observeReasoning(string $uri, string $reasoning): void;
}
