<?php

/**
 * This file is part of Milpa AI Gateway.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{AgentOrchestrator, LlmService, McpClientService};
use PHPUnit\Framework\TestCase;

final class LazyToolContractTest extends TestCase
{
    public function testDiscoveryDoesNotAdvertiseAnIncompleteExecutableSignature(): void
    {
        $schema = ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']];
        $summary = ['name' => 'source_read', 'description' => 'Read an owned source path.', 'inputSchema' => $schema];
        $llm = $this->createMock(LlmService::class);
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([$summary]);
        $executor->expects(self::once())->method('callTool')->with('source_read', ['path' => 'Owned.php'])->willReturn('source');
        $seen = [];
        $llm->expects(self::exactly(3))->method('generateResponse')->willReturnCallback(
            static function (string $prompt, array $tools) use (&$seen): array {
                $seen[] = $tools;
                return match (count($seen)) {
                    1 => self::call('describe_tool', ['name' => 'source_read']),
                    2 => self::call('source_read', ['path' => 'Owned.php']),
                    default => ['role' => 'assistant', 'content' => 'Source read.'],
                };
            },
        );
        (new AgentOrchestrator($llm, $executor, lazyTools: true))->run('Read Owned.php');
        self::assertSame(['describe_tool'], array_column($seen[0], 'name'));
        self::assertStringContainsString('source_read', $seen[0][0]['description']);
        self::assertStringContainsString($summary['description'], $seen[0][0]['description']);
        self::assertSame($summary, $seen[1][0]);
        self::assertSame($schema, $seen[2][0]['inputSchema']);
    }

    public function testFullCatalogueStillOffersTheOriginalRequiredArgumentsImmediately(): void
    {
        $summary = ['name' => 'source_read', 'description' => 'Read source.', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]];
        $llm = $this->createMock(LlmService::class);
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([$summary]);
        $seen = [];
        $llm->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools) use (&$seen): array {
            $seen = $tools;
            return ['role' => 'assistant', 'content' => 'Ready.'];
        });
        (new AgentOrchestrator($llm, $executor))->run('Inspect');
        self::assertSame([$summary], $seen);
    }

    public function testEmptyCatalogueDoesNotOfferADeadDiscoveryDoor(): void
    {
        $llm = $this->createMock(LlmService::class);
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([]);
        $seen = null;
        $llm->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools) use (&$seen): array {
            $seen = $tools;
            return ['role' => 'assistant', 'content' => 'Ready.'];
        });
        (new AgentOrchestrator($llm, $executor, lazyTools: true))->run('Inspect');
        self::assertSame([], $seen);
    }

    /** @param array<string, string> $arguments
     * @return array<string, mixed>
     */
    private static function call(string $name, array $arguments): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => $name, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($arguments)]]]];
    }
}
