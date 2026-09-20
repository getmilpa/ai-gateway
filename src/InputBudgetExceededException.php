<?php

/**
 * This file is part of Milpa AI Gateway.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/** A counted effective request cannot leave the caller's declared output reserve. */
final class InputBudgetExceededException extends InputBudgetException
{
    /** The adapter must bind this count to the complete body it would actually dispatch. */
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $contextTokens,
        public readonly int $outputTokens,
        public readonly string $requestSha256,
    ) {
        if ($inputTokens < 0 || $contextTokens < 1 || $outputTokens < 1 || $outputTokens >= $contextTokens
            || $inputTokens <= $contextTokens - $outputTokens || preg_match('/\A[a-f0-9]{64}\z/D', $requestSha256) !== 1) {
            throw new \InvalidArgumentException('A counted input refusal requires valid limits, a body hash and an exceeded reserve.');
        }
        parent::__construct('The counted input leaves no room for the declared output budget.');
    }

    /** Counted input is distinct from the legacy estimate.
     * @return array<string, int|string>
     */
    public function receipt(): array
    {
        return [
            'source' => 'counted_request',
            'inputTokens' => $this->inputTokens,
            'contextTokens' => $this->contextTokens,
            'outputTokens' => $this->outputTokens,
            'inputLimitTokens' => $this->contextTokens - $this->outputTokens,
            'requestSha256' => $this->requestSha256,
        ];
    }
}
