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
 * Both transport attempts for one chat-completions POST failed — the original try and the
 * single retry {@see LlmService} allows for a transient wire failure (connection refused or
 * reset, timeout, DNS). Its message already carries the `"$provider API Error: ..."` prefix
 * callers expect AND names that two attempts were made, so the catch blocks that re-wrap
 * other throwables must let this one through untouched — wrapping it again would stutter
 * the prefix and bury the attempt count.
 *
 * An HTTP response of any status never becomes this exception: a 4xx/5xx is the provider
 * speaking, not the wire failing, and surfaces exactly as before.
 */
final class TransportRetryExhaustedException extends \RuntimeException
{
}
