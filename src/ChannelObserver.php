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
 * Sees exactly what was sent to the model — the same array that becomes the request body.
 *
 * A gateway records what the agent DID: which tools it called, what came back, which gate stopped
 * it. It records nothing about what the agent was GIVEN — the tools it was offered, the conversation
 * it received, the model it was asked of. That asymmetry is why debugging an agent has meant putting
 * a proxy in front of the endpoint and reading the wire by hand.
 *
 * This is the seam that removes the proxy. It hands over the payload at the point of serialization,
 * so a consumer can record what travelled instead of rebuilding what should have travelled. The
 * distinction is the whole point: a view that reconstructs from code eventually disagrees with the
 * wire, and then it is not a view of the system — it is a second, quieter opinion about it.
 *
 * Implementations MUST NOT throw and MUST NOT be slow. Observing a channel may not change it.
 */
interface ChannelObserver
{
    /**
     * @param string               $uri     Where the request is going — the provider's endpoint, or
     *                                      whichever host the app pointed at instead.
     * @param array<string, mixed> $payload The body, decoded: `model`, `messages`, and `tools` when
     *                                      any were offered, in the shape the provider receives them
     *                                      and not the shape the caller handed in.
     */
    public function observe(string $uri, array $payload): void;
}
