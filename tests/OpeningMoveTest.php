<?php

/**
 * A run can open with a tool call its caller already holds, instead of buying it from the model again.
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
 * The opening move (greenhouse decisions/0577, evidence/1114).
 *
 * A caller sometimes knows, deterministically, the tool call a run must start with: the one it recorded, refused,
 * and whose refusal a person has since lifted. Asking the model for it buys an inference to retype arguments the
 * caller holds byte for byte (greenhouse evidence/1109, call 6: 14,843 tokens and 32 s). With an opening move the
 * loop takes that call as the reply to its first step without contacting the provider, and everything after it is
 * the loop as it always was: the same door, the same recording, the same step count.
 *
 * @guards the opening move is executed through the tool executor, once, as step one, without a provider call; the
 *         model then sees the call and its result as its own conversation; a refusal of the door ends the run as
 *         any refusal does; the move spends a step of the ceiling
 *
 * @refuses words put in the model's mouth (an opening move is tool calls, never content); a move played twice; a
 *          move that skips the door
 */
final class OpeningMoveTest extends TestCase
{
    public function testTheMoveRunsThroughTheDoorWithoutAskingTheModelAndTheModelThenSeesIt(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('make')]);
        $executor->expects(self::once())->method('callTool')->with('make', ['plugin' => 'Blog'])->willReturn('ran in a trial');
        $llm = $this->createMock(LlmService::class);
        $seen = [];
        $llm->expects(self::once())->method('generateResponse')->willReturnCallback(static function (string $prompt, array $tools, array $messages) use (&$seen): array {
            $seen = $messages;

            return ['role' => 'assistant', 'content' => 'Promoted next.'];
        });
        $loop = (new AgentOrchestrator($llm, $executor))->setOpeningMove(self::move('make', ['plugin' => 'Blog']));

        $answer = $loop->run('continue', 'Initial facts.');

        self::assertSame('🔧 Promoted next.', $answer, 'the answer of a run that used tools, as always');
        self::assertSame(RunEnd::FinalAnswer, $loop->termination()->reason);
        self::assertSame(
            [['role' => 'system', 'content' => 'Initial facts.'], ['role' => 'user', 'content' => 'continue'], self::move('make', ['plugin' => 'Blog'])],
            \array_slice($seen, 0, 3),
        );
        self::assertSame('tool', $seen[3]['role']);
        self::assertSame('ran in a trial', $seen[3]['content']);
        self::assertSame('resumed-1', $seen[3]['tool_call_id'] ?? null);
    }

    public function testTheMoveSpendsAStepOfTheCeiling(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('make')]);
        $executor->expects(self::once())->method('callTool')->willReturn('ran in a trial');
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generateResponse');
        $loop = (new AgentOrchestrator($llm, $executor, 1))->setOpeningMove(self::move('make', ['plugin' => 'Blog']));

        $loop->run('continue');

        self::assertSame(RunEnd::StepsExhausted, $loop->termination()->reason);
    }

    public function testARefusalOfTheDoorEndsTheRunAsAnyRefusalDoes(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('make')]);
        $executor->method('callTool')->willThrowException(new ToolCallRefused('The session asks first.'));
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::never())->method('generateResponse');
        $loop = (new AgentOrchestrator($llm, $executor))->setOpeningMove(self::move('make', ['plugin' => 'Blog']));

        self::assertSame('The session asks first.', $loop->run('continue'));
        self::assertSame(RunEnd::ToolRefused, $loop->termination()->reason);
    }

    public function testTheMoveIsPlayedOnce(): void
    {
        $called = [];
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('make')]);
        $executor->method('callTool')->willReturnCallback(static function (string $name, array $arguments) use (&$called): string {
            $called[] = $arguments['plugin'] ?? '?';

            return 'ok';
        });
        $llm = $this->createMock(LlmService::class);
        $asked = 0;
        $llm->method('generateResponse')->willReturnCallback(static function () use (&$asked): array {
            return ++$asked === 1 ? self::move('make', ['plugin' => 'Shop'], 'model-1') : ['role' => 'assistant', 'content' => 'Done.'];
        });
        $loop = (new AgentOrchestrator($llm, $executor))->setOpeningMove(self::move('make', ['plugin' => 'Blog']));

        self::assertSame('🔧 Done.', $loop->run('continue'));
        self::assertSame(['Blog', 'Shop'], $called, 'the move opened the run and the model played the rest');
        self::assertSame(2, $asked);

        self::assertSame('Done.', $loop->run('again'));
        self::assertSame(['Blog', 'Shop'], $called, 'a second run of the same loop does not replay it');
        self::assertSame(3, $asked);
    }

    public function testNullWithdrawsTheMove(): void
    {
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('make')]);
        $executor->expects(self::never())->method('callTool');
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::once())->method('generateResponse')->willReturn(['role' => 'assistant', 'content' => 'Asked.']);
        $loop = (new AgentOrchestrator($llm, $executor))->setOpeningMove(self::move('make', ['plugin' => 'Blog']))->setOpeningMove(null);

        self::assertSame('Asked.', $loop->run('continue'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notMoves')]
    public function testWordsAreNeverPutInTheModelsMouth(array $notAMove): void
    {
        $loop = new AgentOrchestrator($this->createMock(LlmService::class), $this->createMock(McpClientService::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An opening move is tool calls its caller holds');
        $loop->setOpeningMove($notAMove);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function notMoves(): iterable
    {
        yield 'an answer' => [['role' => 'assistant', 'content' => 'The work is done.']];
        yield 'a call with words beside it' => [['role' => 'assistant', 'content' => 'I will promote it.'] + self::move('make', [])];
        yield 'no calls' => [['role' => 'assistant', 'content' => '', 'tool_calls' => []]];
        yield 'a user turn' => [['role' => 'user'] + self::move('make', [])];
        yield 'a call with no id' => [['role' => 'assistant', 'content' => '', 'tool_calls' => [['type' => 'function', 'function' => ['name' => 'make', 'arguments' => '{}']]]]];
        yield 'a call with no name' => [['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'x', 'type' => 'function', 'function' => ['arguments' => '{}']]]]];
        yield 'a call whose arguments are not JSON text' => [['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'x', 'type' => 'function', 'function' => ['name' => 'make', 'arguments' => ['plugin' => 'Blog']]]]]];
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
    private static function move(string $name, array $arguments, string $id = 'resumed-1'): array
    {
        return ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode((object) $arguments)]]]];
    }
}
