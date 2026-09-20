<?php

/**
 * This file is part of Milpa AI Gateway.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/** A caller-selected request guard refused generation before provider dispatch. */
abstract class InputBudgetException extends \RuntimeException
{
    /** The guard's observation, never a completion claim.
     * @return array<string, int|string>
     */
    abstract public function receipt(): array;
}
