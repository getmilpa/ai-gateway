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

use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallGate as GateContract;
use Milpa\ToolRuntime\Gate\ToolCallRecorder as RecorderContract;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * The model's tool calls: {@see GatedToolCalls} shaped for {@see AgentOrchestrator}.
 *
 * Gate, registry and recorder live in milpa/tool-runtime — a model is ONE caller of tools, a governed door
 * is another, and both ask the same question before each call (greenhouse decisions/0225). What this
 * class adds is the model's own concern: the {@see OptionTable}, which takes options off the catalogue and
 * marks a refusal that names one of them.
 */
class McpClientService extends GatedToolCalls
{
    /**
     * @param GateContract|null     $gate     consulted before every call; may refuse it. Without a gate the
     *                                        loop runs as it ran: the absence of a policy cannot be a new policy
     * @param RecorderContract|null $recorder told after, with what the tool answered
     * @param OptionTable|null      $mesa     which options are still on the table. Without one the catalogue
     *                                        is the whole registry, which is how it ran before
     */
    public function __construct(
        ToolRegistry $internalRegistry,
        ?GateContract $gate = null,
        ?RecorderContract $recorder = null,
        private readonly ?OptionTable $mesa = null,
    ) {
        parent::__construct($internalRegistry, $gate, $recorder);
    }

    /**
     * What the table took away leaves the catalogue the model sees.
     *
     * @return list<string>
     */
    protected function hidden(): array
    {
        return $this->mesa?->removed() ?? [];
    }

    /** A refusal of an option the table already removed is a different fact from one never offered. */
    protected function optionRemoved(string $tool): bool
    {
        return $this->mesa?->wasRemoved($tool) ?? false;
    }
}
