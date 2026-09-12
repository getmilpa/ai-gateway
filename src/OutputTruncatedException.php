<?php

/**
 * This file is part of Milpa AI Gateway.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/** The provider exhausted output tokens; no call from that incomplete message may execute. */
final class OutputTruncatedException extends \RuntimeException
{
    /** The limit is the request's value, not a claim about the provider's token usage. */
    public function __construct(
        public readonly string $provider,
        public readonly int $maxTokens,
        public readonly string $stopReason = 'length',
    ) {
        parent::__construct("{$provider} response was truncated ({$stopReason}; requested output limit: {$maxTokens}); no tool calls from this incomplete response were executed.");
    }
}
