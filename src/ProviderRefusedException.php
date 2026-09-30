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
 * The model's endpoint answered with an HTTP error status — the provider SPEAKING, not the wire failing.
 *
 * Its message is the `"$provider API Error: HTTP …"` text callers already read, byte for byte; the type adds
 * what that text buried: the status and the endpoint that answered it (as {@see ProviderEndpoint::shown()}
 * spells it, so no credential travels). {@see AgentOrchestrator} carries both into the run's termination, so
 * a turn that died on its endpoint says so on the panel instead of ending as a bare `failed`
 * (greenhouse evidence/1069 §C1).
 */
final class ProviderRefusedException extends \RuntimeException
{
    /**
     * @param string          $message  the provider-prefixed message callers already read
     * @param int             $status   the HTTP status the endpoint answered
     * @param string          $endpoint the endpoint that answered it, already shown without credentials
     * @param \Throwable|null $previous what caused it, when something did
     */
    public function __construct(string $message, public readonly int $status, public readonly string $endpoint, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
