<?php

/**
 * This file is part of Milpa AI Gateway.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/** An opted-in guard could not establish a count; no estimate substitutes for it. */
final class InputBudgetUnavailableException extends InputBudgetException
{
    /** Keep the failed request's identity without copying its potentially sensitive body. */
    public function __construct(public readonly string $requestSha256, ?\Throwable $previous = null)
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $requestSha256) !== 1) {
            throw new \InvalidArgumentException('An unavailable input count requires the effective request body hash.');
        }
        parent::__construct('The requested input count is unavailable; generation was not dispatched.', 0, $previous);
    }

    /** Missing evidence never becomes a fabricated zero.
     * @return array<string, string>
     */
    public function receipt(): array
    {
        return ['source' => 'count_unavailable', 'requestSha256' => $this->requestSha256];
    }
}
