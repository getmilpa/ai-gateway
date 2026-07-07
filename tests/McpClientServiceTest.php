<?php

/**
 * This file is part of Milpa AI Gateway — the dual-provider LLM client and agentic
 * tool-use runtime for the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\AiGateway\McpClientService;
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
}
