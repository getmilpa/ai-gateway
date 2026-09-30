<?php

/**
 * This file is part of Milpa AI Gateway.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/**
 * The provider exhausted output tokens; no call from that incomplete message may execute.
 *
 * `retried` is true when the loop already gave the call its one shortened retry and that retry was cut too
 * (greenhouse decisions/0542): the leg ends here, and the message says so.
 */
final class OutputTruncatedException extends \RuntimeException
{
    /** The limit is the request's value, not a claim about the provider's token usage. */
    public function __construct(
        public readonly string $provider,
        public readonly int $maxTokens,
        public readonly string $stopReason = 'length',
        public readonly bool $retried = false,
    ) {
        parent::__construct("{$provider} response was truncated ({$stopReason}; requested output limit: {$maxTokens})"
            . ($retried ? ' after one shortened retry' : '')
            . '; no tool calls from this incomplete response were executed.');
    }
}
