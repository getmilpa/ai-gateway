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
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\ToolCallRefusedException;
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

    /**
     * A pathological tool result (a self-inspection dump measured at ~53K tokens, greenhouse
     * evidence/0440) must be BOUNDED before it returns to the model inside the turn — otherwise it
     * overflows a small-window model in one step, before any history compaction applies. The head is
     * kept, the tail is elided with an honest marker. The existing small-result tests are the
     * negative control: if the bound touched a normal result, they would fail.
     */
    public function testABigToolResultIsBoundedBeforeItReturnsToTheModel(): void
    {
        $tools = [
            ['name' => 'observe', 'description' => 'dump the whole session', 'inputSchema' => []],
        ];
        $this->mcpClient->method('getToolSummaries')->willReturn($tools);

        $huge = str_repeat('X', 60000);
        $this->mcpClient->method('callTool')->with('observe', [])->willReturn($huge);

        $captured = [];
        $call = 0;
        $this->llmService->method('generateResponse')
            ->willReturnCallback(function ($prompt, $tools, $messages, $maxTokens) use (&$captured, &$call) {
                $captured[] = $messages;
                ++$call;
                if ($call === 1) {
                    return [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'observe', 'arguments' => '{}']],
                        ],
                    ];
                }

                return ['role' => 'assistant', 'content' => 'done'];
            });

        $this->orchestrator->run('inspect');

        // The SECOND model call carried the tool result. It must be bounded — never the full 60000-char
        // dump — and it must SAY it was cut rather than truncate silently.
        $this->assertArrayHasKey(1, $captured, 'the model was called again after the tool ran');
        $toolMsg = null;
        foreach ($captured[1] as $m) {
            if (($m['role'] ?? '') === 'tool') {
                $toolMsg = $m;
            }
        }
        $this->assertNotNull($toolMsg, 'the tool result was fed back to the model');
        $this->assertLessThan(9000, mb_strlen($toolMsg['content']), 'the 60000-char dump was bounded');
        $this->assertStringStartsWith('XXXX', $toolMsg['content'], 'the head of the result is kept');
        $this->assertStringContainsString('truncated', $toolMsg['content']);
        $this->assertStringContainsString('elided', $toolMsg['content']);
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

    // ---- /force and the step callback ----------------------------------------

    public function testForceStripsThePrefixClearsHistoryAndTellsTheModelToUseTools(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $capturado = null;
        $this->llmService->method('generateResponse')
            ->willReturnCallback(function (string $prompt, array $tools, array $messages) use (&$capturado): array {
                $capturado = $messages;

                return ['role' => 'assistant', 'content' => 'listo'];
            });

        $this->orchestrator->run('/force dame el estado', 'Eres útil.', [
            ['role' => 'user', 'content' => 'algo viejo'],
        ]);

        self::assertNotNull($capturado);
        self::assertCount(2, $capturado, 'History was cleared: only the system prompt and the user turn remain.');
        self::assertStringContainsString('You must use tools', $capturado[0]['content']);
        self::assertSame('dame el estado', $capturado[1]['content'], 'The /force prefix is not part of the question.');
    }

    public function testAStepCallbackThatThrowsDoesNotTakeTheRunDownWithIt(): void
    {
        // The callback exists to refresh a typing indicator. A transport hiccup
        // there must not cost the user their answer.
        $this->mcpClient->method('getToolSummaries')->willReturn([]);
        $this->llmService->method('generateResponse')
            ->willReturn(['role' => 'assistant', 'content' => 'la respuesta']);

        $result = $this->orchestrator->run('hola', 'Eres útil.', [], static function (): void {
            throw new \RuntimeException('el indicador se cayó');
        });

        self::assertSame('la respuesta', $result);
    }

    /**
     * UNA NEGATIVA TERMINA LA VUELTA — el modelo no vuelve a ser consultado.
     *
     * Es la diferencia entre una compuerta y una sugerencia. Si la negativa cayera en el catch
     * genérico, el modelo la leería como el resultado de una herramienta y probaría otra cosa:
     * seguiría trabajando ALREDEDOR de la compuerta, y el humano al que se le iba a preguntar se
     * enteraría cuando ya se hizo algo distinto. Esta prueba es lo que hace que mover ese catch deje
     * de ser un cambio inocente.
     */
    public function testARefusedToolCallEndsTheRunInsteadOfFeedingItBackToTheModel(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'make', 'description' => 'Andamia', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')
            ->willThrowException(new ToolCallRefusedException('«make» necesita permiso en esta sesión'));

        // UNA sola vez: si el bucle continuara, habría una segunda.
        $this->llmService->expects($this->once())
            ->method('generateResponse')
            ->willReturn([
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    ['id' => 'c1', 'function' => ['name' => 'make', 'arguments' => '{}']],
                ],
            ]);

        $respuesta = $this->orchestrator->run('crea una entity');

        $this->assertStringContainsString('necesita permiso', $respuesta, 'el motivo llega a quien preguntó');
    }

    /**
     * Un fallo NORMAL de herramienta sí vuelve al modelo, y el bucle sigue.
     *
     * Es la otra mitad del contrato: una herramienta que truena por un argumento malo es algo que el
     * modelo puede corregir, y devolvérselo es lo que le permite hacerlo. Sin esta prueba, «detener
     * ante una negativa» podría implementarse deteniéndose ante todo.
     */
    public function testAnOrdinaryToolFailureStillGoesBackToTheModel(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'make', 'description' => 'Andamia', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')->willThrowException(new \Exception('campo desconocido'));

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        ['id' => 'c1', 'function' => ['name' => 'make', 'arguments' => '{}']],
                    ],
                ],
                ['role' => 'assistant', 'content' => 'corrijo el campo'],
            );

        $respuesta = $this->orchestrator->run('crea una entity');

        $this->assertStringContainsString('corrijo el campo', $respuesta);
    }

    /**
     * LA MESA SE VUELVE A PROYECTAR EN CADA PASO, y el modelo ve la nueva.
     *
     * Ésta es la costura que hacía imposible el experimento de Q-P19-H: el catálogo se pedía una sola
     * vez antes del bucle, así que una opción retirada a media corrida seguía enfrente hasta el final.
     * Sin esto, preguntar «¿el agente volvió a mirar el mundo?» no tiene sentido — el mundo no cambiaba.
     *
     * Se afirma sobre lo que RECIBE el modelo, no sobre cuántas veces se llamó al cliente: contar
     * llamadas probaría que el código corre, no que el efecto llega.
     */
    public function testTheTableIsProjectedAgainOnEveryStepAndTheModelSeesIt(): void
    {
        $completa = [
            ['name' => 'plugins_disable', 'description' => 'apaga', 'inputSchema' => []],
            ['name' => 'plugins_simulate', 'description' => 'simula', 'inputSchema' => []],
        ];
        $sinLaQueMuta = [$completa[1]];

        // Primer paso con las dos; a partir del segundo, una menos — como si la compuerta la hubiera
        // retirado tras negar la llamada.
        $this->mcpClient->method('getToolSummaries')
            ->willReturnOnConsecutiveCalls($completa, $sinLaQueMuta, $sinLaQueMuta);
        $this->mcpClient->method('callTool')->willReturn('ok');

        $vistos = [];
        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnCallback(function (string $p, array $tools, array $m) use (&$vistos): array {
                $vistos[] = array_column($tools, 'name');

                return \count($vistos) === 1
                    ? [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            ['id' => 'c1', 'function' => ['name' => 'plugins_simulate', 'arguments' => '{}']],
                        ],
                    ]
                    : ['role' => 'assistant', 'content' => 'ya vi el grafo'];
            });

        $this->orchestrator->run('¿qué deja de funcionar si deshabilito X?');

        self::assertSame(['plugins_disable', 'plugins_simulate'], $vistos[0]);
        self::assertSame(['plugins_simulate'], $vistos[1], 'el segundo paso ve la mesa nueva, no la foto');
    }

    /**
     * UNA NEGATIVA QUE RETIRÓ LA OPCIÓN NO TERMINA LA VUELTA: el motivo vuelve al modelo y el bucle
     * sigue.
     *
     * Es el eslabón que faltaba de la cadena. Sin esto, quitar la opción no sirve de nada: la corrida
     * se acaba en la negativa y el agente nunca llega a ver la mesa nueva. Medido el 2026-08-02 contra
     * el modelo real, con la retirada ya puesta: `steps: 1`, y la respuesta era el veredicto del
     * verificador repetido — exactamente el brazo A de Q-P19-D.
     */
    public function testARefusalThatRemovedTheOptionKeepsTheLoopGoing(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'plugins_simulate', 'description' => 'simula', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')->willThrowException(
            new \Milpa\AiGateway\ToolCallRefusedException('eso va más allá de lo que se pidió', optionRemoved: true),
        );

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        ['id' => 'c1', 'function' => ['name' => 'plugins_disable', 'arguments' => '{}']],
                    ],
                ],
                ['role' => 'assistant', 'content' => 'entonces lo simulo'],
            );

        self::assertStringContainsString('entonces lo simulo', $this->orchestrator->run('¿qué pasa si…?'));
    }

    /**
     * Y una negativa que NO retiró nada sigue terminando la vuelta.
     *
     * Es el piso pidiendo permiso o exigiendo firma: hay una pregunta abierta esperando a un humano, y
     * seguir sería contestarla por él. Esta prueba es la que impide que el arreglo de arriba se
     * generalice a la negativa que sí tiene que detener al agente.
     */
    public function testARefusalThatRemovedNothingStillEndsTheRun(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'plugins_disable', 'description' => 'apaga', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')->willThrowException(
            new \Milpa\AiGateway\ToolCallRefusedException('hace falta permiso'),
        );

        $this->llmService->expects($this->once())
            ->method('generateResponse')
            ->willReturn([
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    ['id' => 'c1', 'function' => ['name' => 'plugins_disable', 'arguments' => '{}']],
                ],
            ]);

        self::assertSame('hace falta permiso', $this->orchestrator->run('apágalo'));
    }

    /**
     * LA RESPUESTA ES LO QUE EL AGENTE DIJO — sin el JSON crudo pegado debajo.
     *
     * El bucle anexaba el resultado de cada herramienta bajo un «📊 Datos:», con una heurística de
     * duplicados que buscaba identificadores numéricos y que contra datos reales nunca acertaba: el
     * bloque salía siempre y duplicaba lo que el modelo ya había transcrito. Pintar el dato es
     * trabajo de la superficie —el resultado viaja en la proyección de la sesión— y no del modelo.
     */
    public function testTheAnswerCarriesNoRawToolDump(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'plugins_list', 'description' => 'lista', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')->willReturn('{"plugins":[{"name":"HelloPlugin"}]}');

        $this->llmService->method('generateResponse')->willReturnOnConsecutiveCalls(
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'plugins_list', 'arguments' => '{}']]],
            ],
            ['role' => 'assistant', 'content' => 'Hay un plugin: HelloPlugin.'],
        );

        $respuesta = $this->orchestrator->run('¿que plugins hay?');

        self::assertStringContainsString('Hay un plugin: HelloPlugin.', $respuesta);
        self::assertStringNotContainsString('📊', $respuesta, 'el anexo se fue');
        self::assertStringNotContainsString('{"plugins"', $respuesta, 'y el JSON crudo con el');
    }

    /**
     * EL PLAN SE REPROYECTA EN CADA PASO — y es lo que Q-P20-B mide.
     *
     * El bucle no conocía `Todo`: el agente escribía su plan al stream y nada se lo volvía a poner
     * enfrente. La prueba mira que el tablero se PREGUNTE una vez por paso, no una vez por corrida;
     * un tablero pedido al arranque sería otra foto, que es el defecto que esto arregla.
     */
    public function testThePlanIsReprojectedOnEveryStep(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'plugins_list', 'description' => 'lista', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')->willReturn('{"ok":true}');

        $veces = 0;
        $tablero = new class ($veces) implements PlanBoard {
            public function __construct(private int &$veces)
            {
            }

            public function current(): ?string
            {
                ++$this->veces;

                return '## Tu plan — vuelta ' . $this->veces;
            }
        };

        $vistos = [];
        $this->llmService->method('generateResponse')->willReturnCallback(
            function (string $p, array $t, array $messages) use (&$vistos): array {
                $planes = array_values(array_filter(
                    $messages,
                    static fn (array $m): bool => ($m['role'] ?? '') === 'system' && str_contains((string) ($m['content'] ?? ''), 'Tu plan'),
                ));
                $vistos[] = $planes;

                return \count($vistos) < 2
                    ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'plugins_list', 'arguments' => '{}']]]]
                    : ['role' => 'assistant', 'content' => 'listo'];
            }
        );

        $orquestador = new AgentOrchestrator($this->llmService, $this->mcpClient, 5, null, null, $tablero);
        $orquestador->run('haz algo de tres pasos');

        self::assertSame(2, $veces, 'se preguntó una vez por paso, no una por corrida');

        // NO SE ACUMULA. Veinte pasos serían veinte fotos del plan, diecinueve de ellas mintiendo, y
        // el estado más viejo quedaría indistinguible del vigente.
        foreach ($vistos as $paso => $planes) {
            self::assertCount(1, $planes, "el paso {$paso} vio exactamente un plan");
        }
        self::assertStringContainsString('vuelta 2', (string) $vistos[1][0]['content'], 'y el del segundo paso es el nuevo');
    }

    /**
     * UN TABLERO QUE DICE `null` ES INDISTINGUIBLE DE NO TENER TABLERO.
     *
     * Las dos vías tienen que producir EXACTAMENTE los mismos mensajes. Es la propiedad que sostiene
     * el brazo de control de Q-P20-B: si una sesión sin plan todavía inyectara un encabezado vacío,
     * el brazo que mide «sin reproyección» estaría midiendo una reproyección de cero tarjetas, y la
     * diferencia contra el otro brazo dejaría de ser atribuible.
     *
     * Y es también la razón de que el default sea `null`: mientras la pregunta esté abierta, lo que se
     * despacha es lo ya medido.
     */
    public function testABoardThatSaysNullIsTheSameAsNoBoard(): void
    {
        $vacio = new class () implements PlanBoard {
            public function current(): ?string
            {
                return null;
            }
        };

        self::assertSame($this->mensajesDeUnaVuelta(null), $this->mensajesDeUnaVuelta($vacio));
    }

    /**
     * Los mensajes que le llegaron al modelo en una vuelta, con el tablero que se le pase.
     *
     * @return list<array<string, mixed>>
     */
    private function mensajesDeUnaVuelta(?PlanBoard $tablero): array
    {
        $llm = $this->createMock(LlmService::class);
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([]);

        $vistos = [];
        $llm->method('generateResponse')->willReturnCallback(
            function (string $p, array $t, array $messages) use (&$vistos): array {
                $vistos = $messages;

                return ['role' => 'assistant', 'content' => 'listo'];
            }
        );

        (new AgentOrchestrator($llm, $mcp, 5, null, null, $tablero))->run('hola');

        return $vistos;
    }
    /**
     * The toolbox (opt-in): tools arrive lean, the model describes one to get its schema, then calls it.
     * The describe is intercepted (never a real tool call); the tool executes once, after it is unlocked
     * (greenhouse evidence/0436).
     */
    public function testTheToolboxServesSchemasOnDemand(): void
    {
        $orchestrator = new AgentOrchestrator($this->llmService, $this->mcpClient, 10, $this->logger, null, null, true);

        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'get_time', 'description' => 'Get current time', 'inputSchema' => ['type' => 'object', 'properties' => ['tz' => ['type' => 'string']]]],
        ]);
        $this->mcpClient->expects($this->once())->method('callTool')
            ->with('get_time', [])
            ->willReturn('12:00 PM');

        $this->llmService->expects($this->exactly(3))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'd1', 'type' => 'function', 'function' => ['name' => 'describe_tool', 'arguments' => '{"name":"get_time"}']]]],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'get_time', 'arguments' => '{}']]]],
                ['role' => 'assistant', 'content' => 'It is 12:00 PM.'],
            );

        $result = $orchestrator->run('What time is it?');
        $this->assertStringContainsString('12:00 PM', $result);
    }

    // ========== Degenerate-answer guard (greenhouse: the SEQ-308 deaths) ==========
    //
    // Measured twice on a live session (qwen3.8-27b): the model spent its completion on
    // reasoning (47591 chars the second time) and answered ONE token. The loop took that as the
    // final answer — honest but useless, when one guided retry would likely have yielded the
    // answer the model had ALREADY reasoned out. The guard fires only on the measured shape:
    // near-empty content AND no tool calls AND large reasoning. One retry, never a third call.

    /** The measured degenerate shape: huge reasoning, one-token answer, no tool calls. */
    private static function seq308Response(): array
    {
        return [
            'role' => 'assistant',
            'content' => '🔧 ',
            'reasoning_content' => str_repeat('r', 47591),
        ];
    }

    public function testTheSeq308ShapeFiresTheGuardAndTheHealthyRetryAnswerStands(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $seen = [];
        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnCallback(function (string $prompt, array $tools, array $messages) use (&$seen): array {
                $seen[] = $messages;

                return count($seen) === 1
                    ? self::seq308Response()
                    : ['role' => 'assistant', 'content' => 'The manifest lacks a version field; add one and reload.'];
            });

        $result = $this->orchestrator->run('Why did the plugin fail?');

        $this->assertSame('The manifest lacks a version field; add one and reload.', $result);

        // The guided retry is the SAME messages plus ONE appended system line telling the model
        // to deliver the answer it already reasoned — nothing else about the call changes.
        $this->assertCount(count($seen[0]) + 1, $seen[1]);
        $this->assertSame($seen[0], array_slice($seen[1], 0, count($seen[0])));
        $nudge = $seen[1][count($seen[1]) - 1];
        $this->assertSame('system', $nudge['role']);
        $this->assertStringContainsString('reasoned', $nudge['content']);
    }

    public function testARetryThatAlsoDegeneratesLeavesTheOriginalAnswerStandingAfterExactlyTwoCalls(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(self::seq308Response(), self::seq308Response());

        $result = $this->orchestrator->run('Why did the plugin fail?');

        // Degeneration is surfaced, not hidden: the original one-token answer stands as-is.
        $this->assertSame('🔧 ', $result);
    }

    public function testAHealthyAnswerWithLargeReasoningNeverTriggersAnExtraCall(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $this->llmService->expects($this->once())
            ->method('generateResponse')
            ->willReturn([
                'role' => 'assistant',
                'content' => 'A long, substantive answer that carries the reasoning outcome.',
                'reasoning_content' => str_repeat('r', 50000),
            ]);

        $result = $this->orchestrator->run('Explain.');

        $this->assertSame('A long, substantive answer that carries the reasoning outcome.', $result);
    }

    public function testATerseButRealAnswerWithSmallReasoningIsNotDegenerate(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $this->llmService->expects($this->once())
            ->method('generateResponse')
            ->willReturn([
                'role' => 'assistant',
                'content' => 'Done.',
                'reasoning_content' => 'short thought',
            ]);

        $result = $this->orchestrator->run('Do it.');

        $this->assertSame('Done.', $result);
    }

    public function testAnEmptyAnswerWithNoReasoningIsNotDegenerate(): void
    {
        // A model that says nothing and reasoned nothing is not the measured failure shape;
        // guessing a retry there would be inventing behavior. Byte-identical to before.
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $this->llmService->expects($this->once())
            ->method('generateResponse')
            ->willReturn(['role' => 'assistant', 'content' => '']);

        $result = $this->orchestrator->run('Hello');

        $this->assertSame('', $result);
    }

    public function testTheGuardNeverFiresWhenToolCallsArePresent(): void
    {
        // Empty content + huge reasoning + tool_calls is the model WORKING, not degenerating.
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'get_time', 'description' => 'Get current time', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')->willReturn('12:00 PM');

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                [
                    'role' => 'assistant',
                    'content' => '',
                    'reasoning_content' => str_repeat('r', 50000),
                    'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'get_time', 'arguments' => '{}']]],
                ],
                ['role' => 'assistant', 'content' => 'It is 12:00 PM.'],
            );

        $result = $this->orchestrator->run('What time is it?');

        $this->assertSame('🔧 It is 12:00 PM.', $result);
    }

    public function testAFailingGuidedRetryLeavesTheOriginalAnswerStandingInsteadOfKillingTheRun(): void
    {
        // The guard must never make things worse: if the retry call itself dies (transport,
        // provider error), the degenerate-but-honest original answer still comes back.
        $this->mcpClient->method('getToolSummaries')->willReturn([]);

        $calls = 0;
        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnCallback(function () use (&$calls): array {
                if (++$calls === 1) {
                    return self::seq308Response();
                }

                throw new \RuntimeException('OpenAI API Error: transport failed on 2 attempts');
            });

        $result = $this->orchestrator->run('Why did the plugin fail?');

        $this->assertSame('🔧 ', $result);
    }
}
