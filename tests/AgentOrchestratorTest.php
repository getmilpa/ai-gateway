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

namespace Milpa\AiGateway\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Psr\Log\LoggerInterface;

/**
 * Merged from the two pre-extraction suites (`tests/Unit/AiGateway/AgentOrchestratorTest.php`
 * and `tests/Unit/Plugins/AiGateway/AgentOrchestratorTest.php`) — same behavior under test,
 * duplicated coverage dropped, distinctly-asserting variants kept under distinguishing names.
 */
class AgentOrchestratorTest extends TestCase
{
    private LlmService $llmService;
    private McpClientService $mcpClient;
    private LoggerInterface $logger;
    private AgentOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmService = $this->createMock(LlmService::class);
        $this->mcpClient = $this->createMock(McpClientService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->orchestrator = new AgentOrchestrator(
            $this->llmService,
            $this->mcpClient,
            10,
            $this->logger
        );
    }

    public function testConstructorInitializesProperties(): void
    {
        $orchestrator = new AgentOrchestrator($this->llmService, $this->mcpClient, 10, $this->logger);

        $this->assertInstanceOf(AgentOrchestrator::class, $orchestrator);
    }

    public function testRunWithSimpleResponse(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $this->llmService->method('generateResponse')
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Hello! How can I help you today?',
            ]);

        $result = $this->orchestrator->run('Hello');

        $this->assertEquals('Hello! How can I help you today?', $result);
    }

    public function testRunWithNoToolCalls(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'test_tool', 'description' => 'A test tool'],
        ]);

        $this->llmService->method('generateResponse')->willReturn([
            'role' => 'assistant',
            'content' => 'Hello, how can I help you?',
        ]);

        $result = $this->orchestrator->run('Hello', 'You are helpful.');

        $this->assertEquals('Hello, how can I help you?', $result);
    }

    public function testRunWithToolCall(): void
    {
        $tools = [
            ['name' => 'get_time', 'description' => 'Get current time', 'inputSchema' => []],
        ];

        $this->mcpClient->method('getToolSummaries')->willReturn($tools);
        $this->mcpClient->method('callTool')
            ->with('get_time', [])
            ->willReturn('Current time is 12:00 PM');

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                // First call: LLM wants to use a tool
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        [
                            'id' => 'call_123',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_time',
                                'arguments' => '{}',
                            ],
                        ],
                    ],
                ],
                // Second call: LLM provides final response
                [
                    'role' => 'assistant',
                    'content' => 'The current time is 12:00 PM.',
                ]
            );

        $result = $this->orchestrator->run('What time is it?');

        $this->assertEquals('🔧 The current time is 12:00 PM.', $result);
    }

    public function testRunWithMultipleToolCalls(): void
    {
        $tools = [
            ['name' => 'get_weather', 'description' => 'Get weather', 'inputSchema' => []],
            ['name' => 'get_time', 'description' => 'Get time', 'inputSchema' => []],
        ];

        $this->mcpClient->method('getToolSummaries')->willReturn($tools);

        $callCount = 0;
        $this->mcpClient->method('callTool')
            ->willReturnCallback(function ($name, $args) use (&$callCount) {
                $callCount++;
                if ($name === 'get_weather') {
                    return 'Sunny, 25°C';
                }
                if ($name === 'get_time') {
                    return '3:00 PM';
                }
                return 'Unknown';
            });

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'function' => ['name' => 'get_weather', 'arguments' => '{}'],
                        ],
                        [
                            'id' => 'call_2',
                            'function' => ['name' => 'get_time', 'arguments' => '{}'],
                        ],
                    ],
                ],
                [
                    'role' => 'assistant',
                    'content' => 'The weather is sunny (25°C) and the time is 3:00 PM.',
                ]
            );

        $result = $this->orchestrator->run('What is the weather and time?');

        $this->assertEquals('🔧 The weather is sunny (25°C) and the time is 3:00 PM.', $result);
        $this->assertEquals(2, $callCount);
    }

    public function testRunRespectsMaxSteps(): void
    {
        // Create orchestrator with max 2 steps
        $orchestrator = new AgentOrchestrator(
            $this->llmService,
            $this->mcpClient,
            2,
            $this->logger
        );

        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'infinite_tool', 'description' => 'Never stops', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')->willReturn('Result');

        // LLM always returns tool calls (infinite loop scenario)
        $this->llmService->method('generateResponse')
            ->willReturn([
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_x',
                        'function' => ['name' => 'infinite_tool', 'arguments' => '{}'],
                    ],
                ],
            ]);

        $result = $orchestrator->run('Do something');

        $this->assertStringContainsString('Maximum agent steps reached', $result);
    }

    public function testRunWithToolError(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'failing_tool', 'description' => 'Always fails', 'inputSchema' => []],
        ]);

        $this->mcpClient->method('callTool')
            ->willThrowException(new \Exception('Tool execution failed'));

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'role' => 'assistant',
                    'tool_calls' => [
                        [
                            'id' => 'call_fail',
                            'function' => ['name' => 'failing_tool', 'arguments' => '{}'],
                        ],
                    ],
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Sorry, I encountered an error while executing the tool.',
                ]
            );

        $result = $this->orchestrator->run('Run failing tool');

        // The orchestrator catches the error and continues
        $this->assertStringContainsString('error', strtolower($result));
    }

    public function testRunWithToolErrorReturnsExactPrefixedMessage(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'failing_tool', 'description' => 'A failing tool'],
        ]);

        $this->llmService->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'function' => [
                                'name' => 'failing_tool',
                                'arguments' => '{}',
                            ],
                        ],
                    ],
                ],
                [
                    'role' => 'assistant',
                    'content' => 'There was an error executing the tool.',
                ]
            );

        $this->mcpClient->method('callTool')
            ->willThrowException(new \Exception('Tool failed'));

        $result = $this->orchestrator->run('Use failing tool');

        $this->assertEquals('🔧 There was an error executing the tool.', $result);
    }

    public function testSetToolContext(): void
    {
        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: ['read', 'write']
        );

        $this->mcpClient->expects($this->once())
            ->method('setContext')
            ->with($ctx);

        $result = $this->orchestrator->setToolContext($ctx);

        $this->assertSame($this->orchestrator, $result); // Returns self for chaining
    }

    public function testSetToolContextSetsContextOnMcpClient(): void
    {
        $ctx = ToolContext::cli();

        $this->mcpClient->expects($this->once())
            ->method('setContext')
            ->with($ctx);

        $result = $this->orchestrator->setToolContext($ctx);

        $this->assertSame($this->orchestrator, $result);
    }

    public function testRunWithSystemPrompt(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $capturedMessages = null;
        $this->llmService->method('generateResponse')
            ->willReturnCallback(function ($prompt, $tools, $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return ['role' => 'assistant', 'content' => 'Response'];
            });

        $this->orchestrator->run('Hello', 'You are a helpful assistant that speaks Spanish.');

        // Verify system message is included
        $systemMessage = array_filter($capturedMessages, fn ($m) => $m['role'] === 'system');
        $this->assertNotEmpty($systemMessage);
        $this->assertStringContainsString('Spanish', reset($systemMessage)['content']);
    }

    public function testRunWithHistory(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $capturedMessages = null;
        $this->llmService->method('generateResponse')
            ->willReturnCallback(function ($prompt, $tools, $messages) use (&$capturedMessages) {
                $capturedMessages = $messages;
                return ['role' => 'assistant', 'content' => 'Hello again!'];
            });

        $history = [
            ['role' => 'user', 'content' => 'Hi there'],
            ['role' => 'assistant', 'content' => 'Hello!'],
        ];

        $this->orchestrator->run('Hello again', 'System prompt', $history);

        // Verify history is included (system + history + current prompt = 4 messages)
        $this->assertCount(4, $capturedMessages);
    }

    public function testRunWithHistoryReturnsFinalResponse(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $this->llmService->method('generateResponse')->willReturn([
            'role' => 'assistant',
            'content' => 'Based on our conversation, yes.',
        ]);

        $history = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ];

        $orchestrator = new AgentOrchestrator($this->llmService, $this->mcpClient, 10, $this->logger);

        $result = $orchestrator->run('Continue our chat', 'Be helpful.', $history);

        $this->assertEquals('Based on our conversation, yes.', $result);
    }

    public function testRunWithToolReturningJsonArray(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'get_users', 'description' => 'Get users list', 'inputSchema' => []],
        ]);

        $this->mcpClient->method('callTool')
            ->willReturn(['users' => [['id' => 1, 'name' => 'John'], ['id' => 2, 'name' => 'Jane']]]);

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'role' => 'assistant',
                    'tool_calls' => [
                        ['id' => 'call_1', 'function' => ['name' => 'get_users', 'arguments' => '{}']],
                    ],
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Found 2 users: John and Jane.',
                ]
            );

        $result = $this->orchestrator->run('List users');

        // The response contains the LLM message, potentially with appended tool data
        $this->assertStringContainsString('Found 2 users: John and Jane.', $result);
    }

    public function testGetLastToolResultInitiallyNull(): void
    {
        $this->assertNull($this->orchestrator->getLastToolResult());
    }

    public function testRunWithToolArgsJson(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'search', 'description' => 'Search', 'inputSchema' => []],
        ]);

        $capturedArgs = null;
        $this->mcpClient->method('callTool')
            ->willReturnCallback(function ($name, $args) use (&$capturedArgs) {
                $capturedArgs = $args;
                return ['results' => []];
            });

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'role' => 'assistant',
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'function' => [
                                'name' => 'search',
                                'arguments' => '{"query": "test", "limit": 10}',
                            ],
                        ],
                    ],
                ],
                ['role' => 'assistant', 'content' => 'No results found.']
            );

        $this->orchestrator->run('Search for test');

        $this->assertEquals(['query' => 'test', 'limit' => 10], $capturedArgs);
    }

    public function testRunEmptyContent(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $this->llmService->method('generateResponse')
            ->willReturn([
                'role' => 'assistant',
                'content' => '',
            ]);

        $result = $this->orchestrator->run('Hello');

        $this->assertEquals('', $result);
    }

    public function testRunReachesMaxSteps(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'loop_tool', 'description' => 'A tool'],
        ]);

        // Always return a tool call to hit max steps
        $this->llmService->method('generateResponse')->willReturn([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'function' => [
                        'name' => 'loop_tool',
                        'arguments' => '{}',
                    ],
                ],
            ],
        ]);

        $this->mcpClient->method('callTool')->willReturn('result');

        $orchestrator = new AgentOrchestrator($this->llmService, $this->mcpClient, 3, $this->logger);

        $result = $orchestrator->run('Loop forever');

        $this->assertStringContainsString('Maximum agent steps reached', $result);
    }

    public function testRunWithLegacyConfirmationResponse(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'delete_item', 'description' => 'Delete an item'],
        ]);

        $this->llmService->method('generateResponse')->willReturn([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'function' => [
                        'name' => 'delete_item',
                        'arguments' => '{"id": 1}',
                    ],
                ],
            ],
        ]);

        // Tool returns a confirmation request
        $this->mcpClient->method('callTool')->willReturn([
            'requires_confirmation' => true,
            'message' => 'Are you sure you want to delete this item?',
        ]);

        $result = $this->orchestrator->run('Delete item 1');

        $this->assertStringContainsString('CONFIRMAR', $result);
        $this->assertStringContainsString('CANCELAR', $result);
    }
}
