<?php

/**
 * This file is part of Milpa AI Gateway — the LLM transport and tool loop of the Milpa PHP framework.
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
 * The contract {@see \Milpa\ToolRuntime\Gate\ToolCallGate} — consulted before every tool call; it may refuse it — kept under this
 * name for whoever implemented it here.
 *
 * The question moved down to milpa/tool-runtime, where every caller of tools already depends (greenhouse
 * decisions/0225): a model is one caller, a governed door another. An implementer of THIS interface is still
 * a gate wherever the base is asked for; new code implements the base directly.
 *
 * @deprecated implement {@see \Milpa\ToolRuntime\Gate\ToolCallGate} instead
 */
interface ToolCallGate extends \Milpa\ToolRuntime\Gate\ToolCallGate
{
}
