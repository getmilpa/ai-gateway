<?php

/**
 * What grows during a run rides after the conversation, so each request prolongs the one before.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{AgentOrchestrator, LlmService, McpClientService, ProgressProbe, RunEnd};
use PHPUnit\Framework\TestCase;

/**
 * The trailing projection (greenhouse decisions/0575, evidence/1109 §6.1).
 *
 * A caller that must tell the model something that GROWS with the run — the locators of the results recorded
 * so far — used to write it into the system prompt, through {@see AgentOrchestrator::setSystemPromptProjection()}.
 * The system prompt comes before the conversation: every time that text grew, everything after it stopped being
 * a prefix of the previous request, and the provider read the whole prompt again (13 cold calls of 19; 224 s of
 * 712). The same text after the conversation leaves every request a prolongation of the one before.
 *
 * @guards the trailing text is the request's LAST conversation message, user role, for that request only; what
 *         precedes it is byte for byte what the previous request sent before its own; it is never accumulated;
 *         an empty text adds nothing; the stall notice stays the last word
 *
 * @refuses a trailing system message (provider chat templates reject one that is not at the beginning); a stale
 *          trailing text left in history; a request sent after its projection failed
 */
final class TrailingProjectionTest extends TestCase
{
    public function testTheTextRidesLastAndEachRequestProlongsTheOneBefore(): void
    {
        $seen = $this->run3(static fn (array $tools): string => 'recorded so far: ' . self::$steps);

        self::assertSame(['role' => 'user', 'content' => 'recorded so far: 0'], end($seen[0]));
        self::assertSame(['role' => 'user', 'content' => 'recorded so far: 1'], end($seen[1]));
        self::assertSame(['role' => 'user', 'content' => 'recorded so far: 2'], end($seen[2]));

        foreach ([1, 2] as $i) {
            $before = \array_slice($seen[$i - 1], 0, -1);
            self::assertSame($before, \array_slice($seen[$i], 0, \count($before)), "request $i does not prolong request " . ($i - 1));
            self::assertGreaterThan(\count($before) + 1, \count($seen[$i]), 'the conversation grew between the two');
        }
    }

    public function testItIsNeverAccumulatedIntoTheConversation(): void
    {
        $seen = $this->run3(static fn (array $tools): string => 'recorded so far: ' . self::$steps);

        foreach ($seen as $i => $messages) {
            $trailing = array_values(array_filter($messages, static fn (array $m): bool => str_starts_with((string) ($m['content'] ?? ''), 'recorded so far')));
            self::assertCount(1, $trailing, "request $i carries one trailing text, its own");
            self::assertSame('recorded so far: ' . $i, $trailing[0]['content']);
        }
    }

    public function testTheSystemPromptIsNotTouched(): void
    {
        $seen = $this->run3(static fn (array $tools): string => 'recorded so far: ' . self::$steps);

        foreach ($seen as $messages) {
            self::assertSame(['role' => 'system', 'content' => 'Initial facts.'], $messages[0]);
        }
    }

    public function testAnEmptyTextAddsNoMessage(): void
    {
        $with = $this->run3(static fn (array $tools): string => '');
        $without = $this->run3(null);

        self::assertSame($without, $with);
        self::assertSame('Continue.', $with[0][\count($with[0]) - 1]['content']);
    }

    public function testItReceivesTheExactOfferOfTheStep(): void
    {
        $offered = [];
        $seen = $this->run3(static function (array $tools) use (&$offered): string {
            $offered[] = array_column($tools, 'name');

            return 'x';
        }, [[self::tool('read'), self::tool('write')], [self::tool('write')], [self::tool('read')]]);

        self::assertCount(3, $seen);
        self::assertSame([['read', 'write'], ['write'], ['read']], $offered);
    }

    public function testNullRestoresTheRequestAsItWas(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([]);
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::once())->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools, array $messages): array {
            self::assertSame([['role' => 'system', 'content' => 'Original.'], ['role' => 'user', 'content' => 'Now.']], $messages);

            return ['role' => 'assistant', 'content' => 'Finished control.'];
        });
        $loop = new AgentOrchestrator($llm, $executor);
        $loop->setTrailingProjection(static fn (): string => 'Unexpected.')->setTrailingProjection(null);
        $loop->run('Now.', 'Original.');
    }

    public function testAProjectionThatFailsSendsNothing(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([]);
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generateResponse');
        $loop = new AgentOrchestrator($llm, $executor);
        $loop->setTrailingProjection(static fn (): string => throw new \RuntimeException('projection failed'));
        try {
            $loop->run('Now.');
            self::fail('Projection error was swallowed.');
        } catch (\RuntimeException $error) {
            self::assertSame('projection failed', $error->getMessage());
            self::assertSame(RunEnd::Failed, $loop->termination()->reason);
        }
    }

    public function testTheStallNoticeKeepsTheLastWord(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('write')]);
        $executor->method('callTool')->willReturn('recorded');
        $llm = $this->createMock(LlmService::class);
        $seen = [];
        $llm->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools, array $messages) use (&$seen): array {
            $seen[] = $messages;

            return \count($seen) === 1 ? self::call('write', 'w1') : ['role' => 'assistant', 'content' => 'HOUSE_DEBT: nothing to do here'];
        });
        $probe = new class () implements ProgressProbe {
            public function afterStep(int $step): ?array
            {
                return ['stalled' => true, 'notice' => 'No semantic progress.', 'receipt' => ['calls' => 1]];
            }
        };
        (new AgentOrchestrator($llm, $executor, 10, null, null, null, false, $probe))
            ->setTrailingProjection(static fn (array $tools): string => 'recorded so far')
            ->run('Continue.', 'Initial facts.');

        self::assertSame(['role' => 'user', 'content' => 'recorded so far'], end($seen[0]));
        self::assertSame(
            [['role' => 'user', 'content' => 'recorded so far'], ['role' => 'user', 'content' => 'No semantic progress.']],
            \array_slice($seen[1], -2),
        );
    }

    private static int $steps = 0;

    /**
     * Three requests: two tool calls, then the answer.
     *
     * @param (callable(list<array<string, mixed>>): string)|null $projection
     * @param list<list<array<string, mixed>>>|null               $offers
     *
     * @return list<list<array<string, mixed>>> the messages of each request
     */
    private function run3(?callable $projection, ?array $offers = null): array
    {
        self::$steps = 0;
        $offers ??= [[self::tool('write')], [self::tool('write')], [self::tool('write')]];
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturnOnConsecutiveCalls(...$offers);
        $executor->method('callTool')->willReturn('recorded');
        $llm = $this->createMock(LlmService::class);
        $seen = [];
        $llm->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools, array $messages) use (&$seen): array {
            $seen[] = $messages;
            ++self::$steps;

            return \count($seen) < 3 ? self::call('write', 'write-' . \count($seen)) : ['role' => 'assistant', 'content' => 'Finished control.'];
        });
        $loop = new AgentOrchestrator($llm, $executor);
        if ($projection !== null) {
            $loop->setTrailingProjection($projection);
        }
        $loop->run('Continue.', 'Initial facts.', [['role' => 'user', 'content' => 'Earlier request.'], ['role' => 'assistant', 'content' => 'Earlier answer.']]);

        return $seen;
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
