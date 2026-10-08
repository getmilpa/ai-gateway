<?php

/**
 * What follows deterministically from a tool call is played by the caller, not bought from the model.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{AgentOrchestrator, LlmService, McpClientService, RunEnd};
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use PHPUnit\Framework\TestCase;

/**
 * The continuation (greenhouse decisions/0586).
 *
 * Some tool results determine the call that follows: a producer that ran in a trial which verified, when the house
 * applies the verified trial of that operation. Buying that next call from the model costs an inference
 * to copy an identifier the result just handed over (greenhouse evidence/1109: four such calls, 103,863 tokens and
 * 144 s on one run). With a continuation the caller answers, after each tool call, with the calls that follow from
 * it; the loop plays them as its NEXT STEP without contacting the provider.
 *
 * @guards a continued call is a step of its own: it goes through the same executor, spends a step of the ceiling,
 *         and the model reads it and its result as its own conversation; a refusal ends the run as any refusal
 *         does; a failed tool is not continued; a continuation the run did not reach is never carried into
 *         another run
 *
 * @refuses anything but tool calls from a continuation; a continued call that skips the door or the ceiling
 */
final class ContinuationTest extends TestCase
{
    /** @var list<array{string, array<string, mixed>}> */
    private array $called = [];
    /** @var list<list<array<string, mixed>>> */
    private array $seen = [];

    public function testTheCallThatFollowsIsPlayedAsTheNextStepWithoutAskingTheModel(): void
    {
        $loop = $this->loop([self::call('make', ['plugin' => 'Blog']), 'Applied.'])
            ->setContinuation(static fn (string $tool, array $arguments, mixed $result): ?array => $tool === 'make'
                ? [['name' => 'sandbox_promote', 'arguments' => ['workspace' => 'w1']]]
                : null);

        self::assertSame('🔧 Applied.', $loop->run('Build it.', 'Initial facts.'));

        self::assertSame([['make', ['plugin' => 'Blog']], ['sandbox_promote', ['workspace' => 'w1']]], $this->called);
        self::assertCount(2, $this->seen, 'the model was asked for the producer and for what comes after the promotion, never for the promotion');
        $after = \array_slice($this->seen[1], 2);
        self::assertSame(['assistant', 'tool', 'assistant', 'tool'], array_column($after, 'role'));
        self::assertSame('sandbox_promote', $after[2]['tool_calls'][0]['function']['name']);
        self::assertSame(['workspace' => 'w1'], json_decode($after[2]['tool_calls'][0]['function']['arguments'], true));
        self::assertSame('', $after[2]['content']);
        self::assertSame($after[2]['tool_calls'][0]['id'], $after[3]['tool_call_id']);
        self::assertSame('ran sandbox_promote', $after[3]['content']);
    }

    public function testTheContinuationIsAskedWithTheCallAndWhatItAnswered(): void
    {
        $asked = [];
        $loop = $this->loop([self::call('make', ['plugin' => 'Blog']), 'Done.'])
            ->setContinuation(static function (string $tool, array $arguments, mixed $result) use (&$asked): ?array {
                $asked[] = [$tool, $arguments, $result];

                return null;
            });

        $loop->run('Build it.');

        self::assertSame([['make', ['plugin' => 'Blog'], 'ran make']], $asked);
        self::assertCount(2, $this->seen, 'nothing follows: the model is asked as always');
    }

    public function testAContinuedCallSpendsAStepOfTheCeiling(): void
    {
        $loop = $this->loop([self::call('make', []), 'never asked'], maxSteps: 2)
            ->setContinuation(static fn (string $tool): ?array => $tool === 'make' ? [['name' => 'sandbox_promote', 'arguments' => []]] : null);

        $loop->run('Build it.');

        self::assertSame(RunEnd::StepsExhausted, $loop->termination()->reason);
        self::assertSame(['make', 'sandbox_promote'], array_column($this->called, 0));
        self::assertCount(1, $this->seen, 'the second step was the house\'s; there was no third');
    }

    public function testAContinuationTheRunDidNotReachIsNotCarriedIntoAnother(): void
    {
        $loop = $this->loop([self::call('make', []), 'Asked again.'], maxSteps: 1)
            ->setContinuation(static fn (string $tool): ?array => $tool === 'make' ? [['name' => 'sandbox_promote', 'arguments' => []]] : null);

        $loop->run('Build it.');
        self::assertSame(RunEnd::StepsExhausted, $loop->termination()->reason);
        self::assertSame(['make'], array_column($this->called, 0), 'the ceiling came first: the promotion was not played');

        self::assertSame('Asked again.', $loop->run('continue'));
        self::assertSame(['make'], array_column($this->called, 0), 'and the next run opens by asking the model');
    }

    public function testARefusalOfTheContinuedCallEndsTheRunAsAnyRefusalDoes(): void
    {
        $loop = $this->loop([self::call('make', []), 'never asked'], refuse: 'sandbox_promote')
            ->setContinuation(static fn (string $tool): ?array => $tool === 'make' ? [['name' => 'sandbox_promote', 'arguments' => []]] : null);

        self::assertSame('The session asks first.', $loop->run('Build it.'));
        self::assertSame(RunEnd::ToolRefused, $loop->termination()->reason);
        self::assertCount(1, $this->seen);
    }

    public function testAToolThatFailedIsNotContinued(): void
    {
        $asked = 0;
        $loop = $this->loop([self::call('make', []), 'Read the failure.'], fail: 'make')
            ->setContinuation(static function () use (&$asked): ?array {
                ++$asked;

                return [['name' => 'sandbox_promote', 'arguments' => []]];
            });

        $loop->run('Build it.');

        self::assertSame(0, $asked);
        self::assertSame(['make'], array_column($this->called, 0));
    }

    public function testTheCallsOfOneStepContinueTogetherInOrder(): void
    {
        $two = self::call('make', ['plugin' => 'A']);
        $two['tool_calls'][] = ['id' => 'm2', 'type' => 'function', 'function' => ['name' => 'make', 'arguments' => '{"plugin":"B"}']];
        $loop = $this->loop([$two, 'Both applied.'])
            ->setContinuation(static fn (string $tool, array $arguments): ?array => $tool === 'make'
                ? [['name' => 'sandbox_promote', 'arguments' => ['workspace' => 'w' . $arguments['plugin']]]]
                : null);

        $loop->run('Build both.');

        self::assertSame(['make', 'make', 'sandbox_promote', 'sandbox_promote'], array_column($this->called, 0));
        self::assertSame([['workspace' => 'wA'], ['workspace' => 'wB']], [$this->called[2][1], $this->called[3][1]]);
        $house = array_values(array_filter($this->seen[1], static fn (array $m): bool => ($m['tool_calls'][0]['function']['name'] ?? null) === 'sandbox_promote'));
        self::assertCount(1, $house, 'one step of the house, with both calls');
        self::assertCount(2, $house[0]['tool_calls']);
        self::assertNotSame($house[0]['tool_calls'][0]['id'], $house[0]['tool_calls'][1]['id']);
    }

    public function testAContinuedCallMayContinueToo(): void
    {
        $loop = $this->loop([self::call('make', []), 'Chained.'])
            ->setContinuation(static fn (string $tool): ?array => match ($tool) {
                'make' => [['name' => 'sandbox_promote', 'arguments' => []]],
                'sandbox_promote' => [['name' => 'route_observe', 'arguments' => ['path' => '/blog']]],
                default => null,
            });

        $loop->run('Build it.');

        self::assertSame(['make', 'sandbox_promote', 'route_observe'], array_column($this->called, 0));
        self::assertCount(2, $this->seen, 'each link was a step of the house; the model was asked before and after');
    }

    public function testNullWithdrawsTheContinuation(): void
    {
        $loop = $this->loop([self::call('make', []), 'Done.'])
            ->setContinuation(static fn (): ?array => [['name' => 'sandbox_promote', 'arguments' => []]])
            ->setContinuation(null);

        $loop->run('Build it.');

        self::assertSame(['make'], array_column($this->called, 0));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notCalls')]
    public function testAContinuationAnswersWithCallsOrNothing(mixed $answer): void
    {
        $loop = $this->loop([self::call('make', []), 'never asked'])->setContinuation(static fn (): mixed => $answer);

        try {
            $loop->run('Build it.');
            self::fail('what is not a call was played');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('A continuation answers with tool calls', $error->getMessage());
            self::assertSame(RunEnd::Failed, $loop->termination()->reason);
            self::assertSame(['make'], array_column($this->called, 0));
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function notCalls(): iterable
    {
        yield 'words' => ['promote it'];
        yield 'a call with no name' => [[['arguments' => []]]];
        yield 'a call with an empty name' => [[['name' => '', 'arguments' => []]]];
        yield 'a call whose arguments are text' => [[['name' => 'sandbox_promote', 'arguments' => '{}']]];
        yield 'a call with no arguments' => [[['name' => 'sandbox_promote']]];
        yield 'a map, not a list' => [['name' => 'sandbox_promote', 'arguments' => []]];
    }

    /**
     * A loop whose model answers the script, in order, and whose executor remembers what it ran.
     *
     * @param list<array<string, mixed>|string> $script
     */
    private function loop(array $script, int $maxSteps = 10, ?string $refuse = null, ?string $fail = null): AgentOrchestrator
    {
        $this->called = [];
        $this->seen = [];
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('make'), self::tool('sandbox_promote'), self::tool('route_observe')]);
        $executor->method('callTool')->willReturnCallback(function (string $name, array $arguments) use ($refuse, $fail): string {
            $this->called[] = [$name, $arguments];
            if ($name === $refuse) {
                throw new ToolCallRefused('The session asks first.');
            }
            if ($name === $fail) {
                throw new \RuntimeException('The trial did not succeed.');
            }

            return 'ran ' . $name;
        });
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnCallback(function (string $prompt, array $tools, array $messages) use (&$script): array {
            $this->seen[] = $messages;
            $next = array_shift($script);

            return \is_string($next) ? ['role' => 'assistant', 'content' => $next] : $next;
        });

        return new AgentOrchestrator($llm, $executor, $maxSteps);
    }

    /** @return array<string, mixed> */
    private static function tool(string $name): array
    {
        return ['name' => $name, 'description' => $name, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function call(string $name, array $arguments): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'm1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode((object) $arguments)]]]];
    }
}
