<?php

/** Producer-owned termination cannot be inferred from answer text.
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{AgentOrchestrator,LlmService,RunEnd,RunInterrupted,OutputTruncatedException,ProgressProbe};
use Milpa\ToolRuntime\Gate\{GatedToolCalls,ToolCallRefused};
use Milpa\ToolRuntime\ToolResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunTerminationTest extends TestCase
{
    private const ANSWER = 'The producer returned this same text.';
    private static function call(): array
    {
        return ['role' => 'assistant','tool_calls' => [['id' => 'c1','function' => ['name' => 'read','arguments' => '{}']]]];
    }
    /** @return iterable<string,array{mixed,RunEnd}> */
    public static function terminalTools(): iterable
    {
        yield 'gate refusal' => [new ToolCallRefused(self::ANSWER), RunEnd::ToolRefused];
        yield 'typed confirmation' => [ToolResult::confirmation('Confirm', [], 'read', 'item', 1), RunEnd::ConfirmationRequired];
        yield 'legacy array' => [['requires_confirmation' => true,'message' => 'Confirm'], RunEnd::ConfirmationRequired];
        yield 'legacy JSON' => ['{"requires_confirmation":true,"message":"Confirm"}', RunEnd::ConfirmationRequired];
        yield 'blocked' => [ToolResult::blocked('Blocked by rule'), RunEnd::Blocked];
    }
    #[DataProvider('terminalTools')]
    public function testTerminalToolResultsHaveTheirOwnCause(mixed $result, RunEnd $reason): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::once())->method('generateResponse')->willReturn(self::call());
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $tools->expects(self::once())->method('callTool')->willReturnCallback(static function () use ($result) {
            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;
        });
        $loop = new AgentOrchestrator($llm, $tools);
        self::assertNull($loop->termination());
        $answer = $loop->run('Read');
        self::assertSame($reason, $loop->termination()->reason);
        self::assertSame(['reason' => $reason->value,'receipt' => null], $loop->termination()->toArray());
        if ($reason === RunEnd::ToolRefused) {
            self::assertSame(self::ANSWER, $answer);
        }
    }
    public function testIdenticalTextAndSentinelsAreStillFinalAnswersAndResetDuringEachRun(): void
    {
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $llm = $this->createMock(LlmService::class);
        $answers = [self::ANSWER,AgentOrchestrator::STEPS_EXHAUSTED,AgentOrchestrator::CONTEXT_BUDGET_EXHAUSTED,AgentOrchestrator::PROGRESS_STALLED,'HOUSE_DEBT: a quoted marker'];
        $llm->expects(self::exactly(count($answers)))->method('generateResponse')->willReturnCallback(
            static function () use (&$answers): array {
                return ['role' => 'assistant','content' => array_shift($answers)];
            }
        );
        $loop = new AgentOrchestrator($llm, $tools);
        foreach ($answers as $answer) {
            self::assertSame($answer, $loop->run('Read', onStep:static function () use ($loop): void {
                self::assertNull($loop->termination());
            }));
            self::assertSame(RunEnd::FinalAnswer, $loop->termination()->reason);
        }
    }
    /** @return iterable<string,array{\Throwable,RunEnd}> */
    public static function failures(): iterable
    {
        yield 'interrupt' => [new RunInterrupted('stop'),RunEnd::Interrupted];
        yield 'truncated' => [new OutputTruncatedException('openai', 32),RunEnd::OutputTruncated];
        yield 'exception' => [new \RuntimeException('offline'),RunEnd::Failed];
        yield 'error' => [new \Error('broken'),RunEnd::Failed];
    }
    #[DataProvider('failures')]
    public function testExceptionIdentityAndPerRunReset(\Throwable $error, RunEnd $reason): void
    {
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $llm = $this->createMock(LlmService::class);
        $calls = 0;
        $llm->method('generateResponse')->willReturnCallback(static function () use (&$calls, $error): array {
            if (++$calls === 2) {
                throw $error;
            }
            return ['role' => 'assistant','content' => self::ANSWER];
        });
        $loop = new AgentOrchestrator($llm, $tools);
        $loop->run('Read');
        $first = $loop->termination();
        try {
            $loop->run('Read');
            self::fail('Expected exception');
        } catch (\Throwable $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame($reason, $loop->termination()->reason);
        self::assertSame(RunEnd::FinalAnswer, $first->reason);
        $loop->run('Read');
        self::assertSame(RunEnd::FinalAnswer, $loop->termination()->reason);
        self::assertNotSame($first, $loop->termination());
    }
    public function testWithdrawalAndToolFailureContinueWithoutRecordingATerminalRefusal(): void
    {
        foreach ([new ToolCallRefused('removed', optionRemoved:true),new \RuntimeException('tool failed')] as $error) {
            $tools = $this->createMock(GatedToolCalls::class);
            $tools->method('getToolSummaries')->willReturn([]);
            $tools->expects(self::once())->method('callTool')->willThrowException($error);
            $llm = $this->createMock(LlmService::class);
            $llm->expects(self::exactly(2))->method('generateResponse')->willReturnOnConsecutiveCalls(self::call(), ['role' => 'assistant','content' => self::ANSWER]);
            $loop = new AgentOrchestrator($llm, $tools);
            self::assertSame('🔧 ' . self::ANSWER, $loop->run('Read'));
            self::assertSame(RunEnd::FinalAnswer, $loop->termination()->reason);
        }
    }
    public function testExhaustionAndProgressReceiptAreTyped(): void
    {
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $tools->method('callTool')->willReturn('read');
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturn(self::call());
        $loop = new AgentOrchestrator($llm, $tools, maxSteps:1);
        self::assertSame(AgentOrchestrator::STEPS_EXHAUSTED, $loop->run('Read'));
        self::assertSame(RunEnd::StepsExhausted, $loop->termination()->reason);
        $receipt = ['window' => 4,'reason' => 'no growth'];
        $probe = $this->createMock(ProgressProbe::class);
        $probe->method('afterStep')->willReturn(['stalled' => true,'notice' => 'Stop','receipt' => $receipt,'recovery' => 'exhausted']);
        $loop = new AgentOrchestrator($llm, $tools, progressProbe:$probe);
        $answer = $loop->run('Read');
        self::assertSame(RunEnd::ProgressStalled, $loop->termination()->reason);
        self::assertSame($receipt, $loop->termination()->receipt);
        self::assertSame($receipt, json_decode(explode("\n", $answer, 2)[1], true)['receipt']);
    }
    public function testInvalidTextAndDeclaredDebtHaveDistinctCauses(): void
    {
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $tools->method('callTool')->willReturn('read');
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturn(['role' => 'assistant','content' => '<function=read>{}</function>']);
        $loop = new AgentOrchestrator($llm, $tools);
        $loop->run('Read');
        self::assertSame(RunEnd::InvalidResponse, $loop->termination()->reason);
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnOnConsecutiveCalls(self::call(), ['role' => 'assistant','content' => 'HOUSE_DEBT: cannot proceed']);
        $probe = $this->createMock(ProgressProbe::class);
        $probe->method('afterStep')->willReturn(['stalled' => true,'notice' => 'Choose','receipt' => ['window' => 4]]);
        $loop = new AgentOrchestrator($llm, $tools, progressProbe:$probe);
        self::assertSame('HOUSE_DEBT: cannot proceed', $loop->run('Read'));
        self::assertSame(RunEnd::HouseDebt, $loop->termination()->reason);
    }
}
