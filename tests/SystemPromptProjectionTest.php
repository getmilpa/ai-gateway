<?php

/**
 * Request instructions follow the exact governed offer without accumulating in history.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{AgentOrchestrator, LlmService, McpClientService, RunEnd};
use PHPUnit\Framework\TestCase;

final class SystemPromptProjectionTest extends TestCase
{
    public function testWithdrawalAndRestorationUseTheSameOfferOncePerStep(): void
    {
        $offers = [[self::tool('read'), self::tool('write')], [self::tool('write')], [self::tool('read'), self::tool('write')]];
        $executor = $this->createMock(McpClientService::class);
        $executor->expects(self::exactly(3))->method('getToolSummaries')->willReturnOnConsecutiveCalls(...$offers);
        $executor->expects(self::exactly(2))->method('callTool')->with('write', [])->willReturn('recorded');
        $llm = $this->createMock(LlmService::class);
        $seen = [];
        $llm->expects(self::exactly(3))->method('generateResponse')->willReturnCallback(
            static function (string $prompt, array $tools, array $messages) use (&$seen): array {
                $seen[] = ['tools' => $tools, 'messages' => $messages];
                return count($seen) < 3 ? self::call('write', 'write-' . count($seen)) : ['role' => 'assistant', 'content' => 'Finished control.'];
            },
        );
        $history = [['role' => 'user', 'content' => 'Earlier request.'], ['role' => 'assistant', 'content' => 'Earlier answer.']];
        $bases = [];
        (new AgentOrchestrator($llm, $executor))->setSystemPromptProjection(
            static function (string $base, array $tools) use (&$bases): string {
                $bases[] = $base;
                return $base . '\nOffered: ' . implode(',', array_column($tools, 'name'));
            },
        )->run('Continue.', 'Initial facts.', $history);
        self::assertSame(['Initial facts.', 'Initial facts.', 'Initial facts.'], $bases);
        foreach ($seen as $i => $request) {
            self::assertSame($offers[$i], $request['tools']);
            self::assertSame('Initial facts.\nOffered: ' . implode(',', array_column($offers[$i], 'name')), $request['messages'][0]['content']);
            self::assertSame($history, array_slice($request['messages'], 1, 2));
        }
        self::assertSame(self::call('write', 'write-1'), $seen[1]['messages'][4]);
        self::assertSame('recorded', $seen[1]['messages'][5]['content']);
    }

    public function testLazyProjectionReceivesOnlyExecutableSchemas(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('skill_load')]);
        $llm = $this->createMock(LlmService::class);
        $offered = [];
        $llm->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools, array $messages) use (&$offered): array {
            $offered = $tools;
            self::assertSame('describe_tool', substr($messages[0]['content'], -13));
            return ['role' => 'assistant', 'content' => 'Discovery only.'];
        });
        $projected = [];
        (new AgentOrchestrator($llm, $executor, lazyTools: true))->setSystemPromptProjection(
            static function (string $base, array $tools) use (&$projected): string {
                $projected = $tools;
                self::assertStringContainsString('TOOLBOX:', $base);
                return $base . '\n' . implode(',', array_column($tools, 'name'));
            },
        )->run('Inspect.', 'Initial facts.');
        self::assertSame($offered, $projected);
        self::assertSame(['describe_tool'], array_column($projected, 'name'));
    }

    public function testNullProjectionPreservesTheOriginalSystemAndHistory(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([]);
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::once())->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools, array $messages): array {
            self::assertSame([['role' => 'system', 'content' => 'Original.'], ['role' => 'user', 'content' => 'Now.']], $messages);
            return ['role' => 'assistant', 'content' => 'Finished control.'];
        });
        $loop = new AgentOrchestrator($llm, $executor);
        $loop->setSystemPromptProjection(static fn (): string => 'Unexpected.')->setSystemPromptProjection(null);
        $loop->run('Now.', 'Original.');
    }

    public function testProjectionFailureDoesNotSendStaleInstructions(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([]);
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generateResponse');
        $loop = new AgentOrchestrator($llm, $executor);
        $loop->setSystemPromptProjection(static fn (): string => throw new \RuntimeException('projection failed'));
        try {
            $loop->run('Now.');
            self::fail('Projection error was swallowed.');
        } catch (\RuntimeException $error) {
            self::assertSame('projection failed', $error->getMessage());
            self::assertSame(RunEnd::Failed, $loop->termination()->reason);
        }
    }

    /** @return array<string, mixed> */
    private static function tool(string $name): array
    {
        return ['name' => $name, 'description' => $name, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]];
    }

    /** @return array<string, mixed> */
    private static function call(string $name, string $id): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => '{}']]]];
    }
}
