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
 * Sees what one model call COST, the moment its response is decoded.
 *
 * {@see ChannelObserver} answers "what did the agent travel with?" — the request. This answers a
 * different question the request cannot: "what did that call spend?" Token usage is neither what the
 * agent was GIVEN nor what the agent DID; it is a fact the RESPONSE carries and nothing upstream
 * knows. Bolting it onto the request seam would make {@see ChannelObserver::observe()} lie about
 * when it fires — that method is handed the body at serialization, before any response exists, and
 * must fire even when the call then FAILS. The cost only exists on success, after decode, so it
 * gets its own seam.
 *
 * This is deliberately a SEPARATE, OPTIONAL interface. The gateway reports a return only to an
 * observer that opts in (`instanceof ReturnObserver`); a consumer that cares only about the request
 * keeps the narrow contract it already implements. Implementations MUST NOT throw and MUST NOT be
 * slow: observing a channel may not change it.
 */
interface ReturnObserver
{
    /**
     * Hands over the cost of one call, once its body is decoded on success.
     *
     * @param string               $uri  The endpoint the call went to — the same value its matching
     *                                   {@see ChannelObserver::observe()} received.
     * @param array<string, mixed> $meta What the response reported about the call. Carries `model`
     *                                   (the model that answered) and `usage`, normalized across
     *                                   providers to `prompt_tokens`, `completion_tokens`,
     *                                   `total_tokens`, and `cached_tokens` when the provider
     *                                   declared any. Absent keys mean the provider was silent —
     *                                   never that the count was zero.
     */
    public function observeReturn(string $uri, array $meta): void;
}
