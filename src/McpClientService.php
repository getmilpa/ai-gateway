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
use Milpa\ToolRuntime\Gate\ToolCallRefused;
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
     * @param OptionTable|null      $table    which options are still on the table. Without one the catalogue
     *                                        is the whole registry, which is how it ran before
     */
    public function __construct(
        ToolRegistry $internalRegistry,
        ?GateContract $gate = null,
        ?RecorderContract $recorder = null,
        private readonly ?OptionTable $table = null,
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
        return $this->withdrawn();
    }

    /**
     * The table's current removals forbid execution; record-only history does not.
     *
     * @return list<string>
     */
    protected function withdrawn(): array
    {
        return $this->table?->removed() ?? [];
    }

    /** A refusal of an option the table already removed is a different fact from one never offered. */
    protected function optionRemoved(string $tool): bool
    {
        return $this->table?->wasRemoved($tool) ?? false;
    }

    /**
     * The refusal leaves this class under this package's name.
     *
     * Consumers released against it catch {@see ToolCallRefusedException}; a catch of the base
     * {@see ToolCallRefused} (greenhouse decisions/0225) catches this too. Message and `optionRemoved`
     * travel unchanged — a governed pause must not turn into a plain step failure because the class moved.
     *
     * @param array<string, mixed> $args
     */
    public function callTool(string $name, array $args): mixed
    {
        try {
            return parent::callTool($name, $args);
        } catch (ToolCallRefused $refused) {
            if ($refused instanceof ToolCallRefusedException) {
                throw $refused;
            }

            throw new ToolCallRefusedException($refused->getMessage(), $refused->optionRemoved);
        }
    }
}
