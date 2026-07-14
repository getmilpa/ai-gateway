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

use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * Facade over a {@see ToolRegistry} that shapes it for {@see AgentOrchestrator}:
 * summaries for the LLM's tool list and context-aware invocation.
 */
class McpClientService
{
    private ToolRegistry $internalRegistry;
    private ?ToolContext $context = null;

    public function __construct(ToolRegistry $internalRegistry)
    {
        $this->internalRegistry = $internalRegistry;
    }

    /**
     * Set the execution context for tool calls.
     *
     * This should be called before processing a request to set
     * the user's identity and scopes.
     */
    public function setContext(ToolContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Get the current context.
     */
    public function getContext(): ?ToolContext
    {
        return $this->context;
    }

    /**
     * Get the plain-array tool summaries for LLM/MCP exposure.
     *
     * Delegates to {@see ToolRegistry::getToolSummaries()} — named to match that
     * vocabulary since tool-runtime 0.2 (was `getTools()` through 0.1).
     *
     * In the future, merge with external tools fetched via HTTP/SSE.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, version?: string, outputSchema?: array<string, mixed>}>
     */
    public function getToolSummaries(): array
    {
        return $this->internalRegistry->getToolSummaries();
    }

    /**
     * Execute a tool by name through the underlying registry pipeline.
     *
     * @param array<string, mixed> $args
     */
    public function callTool(string $name, array $args): mixed
    {
        // Call with context if available - ToolRegistry.call() accepts context as 3rd param
        $result = $this->internalRegistry->call($name, $args, $this->context);

        // ToolRegistry.call() returns ToolResult
        // Return raw data for backward compatibility (AgentOrchestrator expects string/array)
        if ($result->success) {
            return $result->data;
        } else {
            throw new \Exception($result->error ?? 'Tool execution failed');
        }
    }
}
