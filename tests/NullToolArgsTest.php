<?php

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use PHPUnit\Framework\TestCase;

/**
 * A model-emitted tool call whose arguments are null, empty, or a bare scalar must reach the tool
 * callback as the EMPTY ARGUMENT SET — never as a non-array that fatals an array-typed callback.
 * Measured live: ConsentBridge::callTool(): Argument #2 ($args) must be of type array, null given.
 */
final class NullToolArgsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function degenerateArguments(): array
    {
        return ['missing' => [''], 'json null' => ['null'], 'bare scalar' => ['"lista"']];
    }

    /** @dataProvider degenerateArguments */
    public function testNonArrayArgumentsReachTheToolAsAnEmptyArray(string $raw): void
    {
        $received = null;

        $llm = $this->createStub(LlmService::class);
        $llm->method('generateResponse')->willReturnOnConsecutiveCalls(
            ['content' => null, 'tool_calls' => [[
                'id' => 'call-1',
                'function' => ['name' => 'echo_args', 'arguments' => $raw],
            ]]],
            ['content' => 'done', 'tool_calls' => []],
        );

        $mcp = $this->createStub(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([[
            'name' => 'echo_args',
            'description' => 'records what it receives',
            'inputSchema' => ['type' => 'object'],
        ]]);
        $mcp->method('callTool')->willReturnCallback(function (string $name, array $args) use (&$received): string {
            $received = $args;

            return '{"ok":true}';
        });

        (new AgentOrchestrator($llm, $mcp, maxSteps: 3))->run('go');

        self::assertSame([], $received, 'the callback must receive the EMPTY set, typed array — never null');
    }
}
