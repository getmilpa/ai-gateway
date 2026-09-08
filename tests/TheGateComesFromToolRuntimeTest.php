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

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\OptionTable;
use Milpa\AiGateway\ToolCallGate as AliasGate;
use Milpa\AiGateway\ToolCallRecorder as AliasRecorder;
use Milpa\AiGateway\ToolCallRefusedException;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The gate is milpa/tool-runtime's; this package consumes it (greenhouse decisions/0225, F2 and F3).
 *
 * A gate typed on the base is accepted here; an implementer of the name this package kept is still a gate
 * where the base is asked for; the refusal the gated calls throw is caught by whoever catches the base — and
 * by whoever still catches this package's name, when this package throws it.
 */
final class TheGateComesFromToolRuntimeTest extends TestCase
{
    private function registry(): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('echo', 'Says back', ['type' => 'object'], static fn (array $a): ToolResult => new ToolResult(true, 'back'));

        return $registry;
    }

    public function testAGateTypedOnTheBaseIsAcceptedAndItsRefusalIsTheBaseType(): void
    {
        $gate = new class () implements ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return 'not now';
            }
        };
        $client = new McpClientService($this->registry(), $gate);
        self::assertInstanceOf(GatedToolCalls::class, $client, 'the model\'s client IS gated tool calls');

        try {
            $client->callTool('echo', []);
            self::fail('refused');
        } catch (ToolCallRefused $refused) {
            self::assertSame('not now', $refused->getMessage());
            self::assertFalse($refused->optionRemoved);
            // UNDER THIS PACKAGE'S NAME: a consumer released against `ToolCallRefusedException` keeps catching
            // what it always caught — a governed pause must not become a plain step failure because the class
            // changed package (found by the adversarial review of decisions/0225).
            self::assertInstanceOf(ToolCallRefusedException::class, $refused);
        }
        try {
            $client->callTool('echo', []);
            self::fail('refused');
        } catch (ToolCallRefusedException $kept) {
            self::assertSame('not now', $kept->getMessage());
        }
    }

    public function testAnImplementerOfTheKeptRecorderNameIsStillARecorderAndIsToldOnce(): void
    {
        $told = [];
        $legacy = new class ($told) implements AliasRecorder {
            /** @param list<string> $told */
            public function __construct(private array &$told)
            {
            }

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->told[] = $tool . ':' . $result . ':' . var_export($ok, true);
            }
        };
        self::assertInstanceOf(ToolCallRecorder::class, $legacy);

        $client = new McpClientService($this->registry(), null, $legacy);
        self::assertSame('back', $client->callTool('echo', []));
        self::assertSame(['echo:back:true'], $told, 'told once, after, with the rendered result');
    }

    public function testAnImplementerOfTheKeptNameIsStillAGateWhereTheBaseIsAskedFor(): void
    {
        $legacy = new class () implements AliasGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return null;
            }
        };
        self::assertInstanceOf(ToolCallGate::class, $legacy);

        $client = new McpClientService($this->registry(), $legacy);
        self::assertSame('back', $client->callTool('echo', []), 'a null refusal lets the call through');
    }

    public function testTheKeptExceptionIsCaughtAsTheBaseAndTheTableStillMarksARemovedOption(): void
    {
        self::assertInstanceOf(ToolCallRefused::class, new ToolCallRefusedException('x'), 'catching the base catches this package\'s name too');

        $table = new class () implements OptionTable {
            /** @var list<string> */
            private array $gone = [];

            public function remove(string $option, string $code, ?string $message = null): void
            {
                $this->gone[] = $option;
            }

            public function wasRemoved(string $option): bool
            {
                return \in_array($option, $this->gone, true);
            }

            public function removed(): array
            {
                return $this->gone;
            }
        };
        $table->remove('echo', 'taken off the table');
        $gate = new class () implements ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return 'refused';
            }
        };
        $client = new McpClientService($this->registry(), $gate, null, $table);
        self::assertSame([], $client->getToolSummaries(), 'what the table removed leaves the catalogue');
        try {
            $client->callTool('echo', []);
            self::fail('refused');
        } catch (ToolCallRefused $refused) {
            self::assertTrue($refused->optionRemoved, 'the table marks the refusal of a removed option');
        }
    }
}
