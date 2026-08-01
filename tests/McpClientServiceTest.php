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
use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\ToolCallGate;
use Milpa\AiGateway\ToolCallRecorder;
use Milpa\AiGateway\ToolCallRefusedException;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Psr\Log\LoggerInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;

class McpClientServiceTest extends TestCase
{
    private ToolRegistry $registry;
    private McpClientService $mcpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registry = new ToolRegistry($this->logger);
        $this->mcpClient = new McpClientService($this->registry);
    }

    public function testGetToolSummariesReturnsRegisteredTools(): void
    {
        $this->registry->register('tool1', 'First tool', [], fn () => null);
        $this->registry->register('tool2', 'Second tool', [], fn () => null);

        $tools = $this->mcpClient->getToolSummaries();

        $this->assertCount(2, $tools);
        $names = array_column($tools, 'name');
        $this->assertContains('tool1', $names);
        $this->assertContains('tool2', $names);
    }

    public function testGetToolSummariesReturnsEmptyForNoTools(): void
    {
        $tools = $this->mcpClient->getToolSummaries();

        $this->assertEmpty($tools);
    }

    public function testCallToolReturnsData(): void
    {
        $this->registry->register(
            'get_user',
            'Get user by ID',
            [],
            fn ($args) => ['id' => $args['id'], 'name' => 'John']
        );

        $result = $this->mcpClient->callTool('get_user', ['id' => 123]);

        $this->assertEquals(['id' => 123, 'name' => 'John'], $result);
    }

    public function testCallToolWithScalarResult(): void
    {
        $this->registry->register(
            'get_time',
            'Get current time',
            [],
            fn ($args) => 'Current time is 12:00 PM'
        );

        $result = $this->mcpClient->callTool('get_time', []);

        $this->assertEquals('Current time is 12:00 PM', $result);
    }

    public function testCallToolThrowsOnError(): void
    {
        $this->registry->register(
            'always_fails',
            'This tool always fails',
            [
                'type' => 'object',
                'required' => ['name'],
                'properties' => ['name' => ['type' => 'string']],
            ],
            fn ($args) => 'success'
        );

        $this->expectException(\Exception::class);

        // Missing required 'name' parameter causes validation error
        $this->mcpClient->callTool('always_fails', []);
    }

    public function testCallToolThrowsForNonexistentTool(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tool not found');

        $this->mcpClient->callTool('nonexistent', []);
    }

    public function testSetContext(): void
    {
        $ctx = new ToolContext(
            principal: 'user:456',
            channel: 'telegram',
            scopes: ['read', 'write']
        );

        $this->mcpClient->setContext($ctx);

        $this->assertSame($ctx, $this->mcpClient->getContext());
    }

    public function testGetContextInitiallyNull(): void
    {
        $this->assertNull($this->mcpClient->getContext());
    }

    public function testCallToolUsesContext(): void
    {
        $capturedCtx = null;
        $this->registry->register(
            'context_aware',
            'Uses context',
            [],
            function ($args) use (&$capturedCtx) {
                $capturedCtx = $args['_ctx'] ?? null;
                return 'done';
            }
        );

        $ctx = new ToolContext(
            principal: 'user:789',
            channel: 'web',
            scopes: ['*']
        );
        $this->mcpClient->setContext($ctx);

        $this->mcpClient->callTool('context_aware', []);

        $this->assertInstanceOf(ToolContext::class, $capturedCtx);
        $this->assertEquals('user:789', $capturedCtx->principal);
    }

    public function testCallToolWithAuthorizationFailure(): void
    {
        $this->registry->register(
            'admin_only',
            'Admin only tool',
            [],
            fn ($args) => 'admin result',
            ToolOptions::fromArray(['scopes' => ['admin:write']])
        );

        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: ['user:read']  // No admin:write scope
        );
        $this->mcpClient->setContext($ctx);

        $this->expectException(\Exception::class);

        $this->mcpClient->callTool('admin_only', []);
    }

    public function testCallToolWithValidAuthorization(): void
    {
        $this->registry->register(
            'read_data',
            'Read data',
            [],
            fn ($args) => ['data' => 'value'],
            ToolOptions::fromArray(['scopes' => ['data:read']])
        );

        $ctx = new ToolContext(
            principal: 'user:123',
            channel: 'telegram',
            scopes: ['data:read', 'data:write']
        );
        $this->mcpClient->setContext($ctx);

        $result = $this->mcpClient->callTool('read_data', []);

        $this->assertEquals(['data' => 'value'], $result);
    }

    public function testGetToolSummariesReturnsCorrectSchema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
                'limit' => ['type' => 'integer', 'default' => 10],
            ],
            'required' => ['query'],
        ];

        $this->registry->register('search', 'Search for items', $schema, fn () => []);

        $tools = $this->mcpClient->getToolSummaries();

        $this->assertEquals('search', $tools[0]['name']);
        $this->assertEquals('Search for items', $tools[0]['description']);
        $this->assertEquals($schema, $tools[0]['inputSchema']);
    }

    /**
     * LA COMPUERTA VA PRIMERO: una llamada negada no toca el registro.
     *
     * Preguntar después sería preguntar cuando el archivo ya se escribió. Que la herramienta no haya
     * corrido es la mitad del contrato; la otra es que se distinga de un fallo, y de eso se encarga el
     * tipo de la excepción.
     */
    public function testARefusedCallNeverReachesTheRegistry(): void
    {
        $corrio = false;
        $this->registry->register('make', 'Andamia', [], function () use (&$corrio) {
            $corrio = true;

            return 'no debería';
        });

        $cliente = new McpClientService($this->registry, new class () implements ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return "«{$tool}» necesita permiso en esta sesión";
            }
        });

        try {
            $cliente->callTool('make', ['what' => 'entity']);
            $this->fail('una llamada negada tiene que lanzar');
        } catch (ToolCallRefusedException $e) {
            $this->assertStringContainsString('necesita permiso', $e->getMessage());
        }

        $this->assertFalse($corrio, 'la herramienta no puede haber corrido');
    }

    /** La compuerta ve el nombre y los ARGUMENTOS: sin ellos no se puede decidir nada útil. */
    public function testTheGateSeesTheToolAndItsArguments(): void
    {
        $visto = null;
        $this->registry->register('make', 'Andamia', [], fn () => 'ok');

        $cliente = new McpClientService($this->registry, new class ($visto) implements ToolCallGate {
            public function __construct(private mixed &$visto)
            {
            }

            public function refuse(string $tool, array $arguments): ?string
            {
                $this->visto = [$tool, $arguments];

                return null;
            }
        });

        $cliente->callTool('make', ['what' => 'entity', 'plugin' => 'Inventario']);

        $this->assertSame(['make', ['what' => 'entity', 'plugin' => 'Inventario']], $visto);
    }

    /** Sin compuerta, corre como corría: la ausencia de política no puede ser una política nueva. */
    public function testWithoutAGateNothingChanges(): void
    {
        $this->registry->register('leer', 'Lee', [], fn () => 'contenido');

        $this->assertSame('contenido', (new McpClientService($this->registry))->callTool('leer', []));
    }

    /**
     * Lo que la herramienta CONTESTÓ se registra — la intención no basta.
     *
     * La compuerta ve qué se va a llamar; eso sirve para decidir y no para registrar, porque una
     * intención no dice si funcionó. Una bitácora que apunta lo que se iba a hacer en vez de lo que
     * pasó se lee igual de segura y miente.
     */
    public function testWhatTheToolAnsweredIsRecorded(): void
    {
        $this->registry->register('leer', 'Lee', [], fn () => ['ok' => true, 'total' => 3]);

        $grabadora = new class () implements ToolCallRecorder {
            /** @var list<array{string, array<string, mixed>, string, bool}> */
            public array $visto = [];

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->visto[] = [$tool, $arguments, $result, $ok];
            }
        };

        (new McpClientService($this->registry, null, $grabadora))->callTool('leer', ['x' => 1]);

        $this->assertCount(1, $grabadora->visto);
        $this->assertSame('leer', $grabadora->visto[0][0]);
        $this->assertSame(['x' => 1], $grabadora->visto[0][1]);
        $this->assertStringContainsString('"total":3', $grabadora->visto[0][2], 'un arreglo va en JSON, no como «Array»');
        $this->assertTrue($grabadora->visto[0][3]);
    }

    /**
     * Un FALLO se registra igual, antes de propagarse.
     *
     * Saber qué se intentó y no funcionó es justo lo que impide intentarlo otra vez en el siguiente
     * turno — la falla más cara de una jornada larga es repetir el trabajo que ya se hizo, y su
     * gemela es repetir el que ya falló.
     */
    public function testAFailureIsRecordedBeforeItPropagates(): void
    {
        $this->registry->register('truena', 'Truena', [], function () {
            throw new \RuntimeException('se cayó');
        });

        $grabadora = new class () implements ToolCallRecorder {
            /** @var list<array{string, string, bool}> */
            public array $visto = [];

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                $this->visto[] = [$tool, $result, $ok];
            }
        };

        try {
            (new McpClientService($this->registry, null, $grabadora))->callTool('truena', []);
        } catch (\Exception) {
            // se espera
        }

        $this->assertCount(1, $grabadora->visto);
        $this->assertFalse($grabadora->visto[0][2], 'quedó marcado como fallo');
    }

    /** Una llamada NEGADA no se registra como corrida: nunca corrió. */
    public function testARefusedCallIsNotRecordedAsHavingRun(): void
    {
        $this->registry->register('make', 'Andamia', [], fn () => 'ok');

        $grabadora = new class () implements ToolCallRecorder {
            public int $veces = 0;

            public function recorded(string $tool, array $arguments, string $result, bool $ok): void
            {
                ++$this->veces;
            }
        };

        $cliente = new McpClientService($this->registry, new class () implements ToolCallGate {
            public function refuse(string $tool, array $arguments): ?string
            {
                return 'necesita permiso';
            }
        }, $grabadora);

        try {
            $cliente->callTool('make', []);
        } catch (ToolCallRefusedException) {
            // se espera
        }

        $this->assertSame(0, $grabadora->veces);
    }
}
