<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{AgentOrchestrator, AnswerJudge, AnswerVerdict, LlmService, ProgressProbe, RunEnd, RunInterrupted};
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnswerJudgeTest extends TestCase
{
    /** @return iterable<string,array{string,RunEnd}> */
    public static function judgments(): iterable
    {
        yield 'accepted' => ['accepted', RunEnd::FinalAnswer];
        yield 'rejected' => ['rejected', RunEnd::AnswerRejected];
        yield 'indeterminate' => ['indeterminate', RunEnd::AnswerIndeterminate];
        yield 'throwing' => ['throw', RunEnd::AnswerIndeterminate];
        yield 'stale' => ['stale', RunEnd::AnswerIndeterminate];
    }

    #[DataProvider('judgments')]
    public function testJudgeConsumesTheRawCandidateWithAndWithoutPendingProgress(string $status, RunEnd $end): void
    {
        foreach ([false, true] as $stalled) {
            $candidate = '{"matches":false}';
            $tools = $this->createMock(GatedToolCalls::class);
            $tools->method('getToolSummaries')->willReturn([]);
            $tools->method('callTool')->willReturn('read only');
            $llm = $this->createMock(LlmService::class);
            $llm->expects(self::exactly(2))->method('generateResponse')->willReturnOnConsecutiveCalls(
                ['role' => 'assistant', 'tool_calls' => [['id' => 'r', 'function' => ['name' => 'read', 'arguments' => '{}']]]],
                ['role' => 'assistant', 'content' => $candidate],
            );
            $receipt = ['progress' => 'stalled', 'newArtifacts' => 0];
            $probe = $this->createMock(ProgressProbe::class);
            $probe->method('afterStep')->willReturn($stalled ? ['stalled' => true, 'notice' => 'Recover', 'receipt' => $receipt, 'recovery' => 'pending'] : null);
            $judge = $this->createMock(AnswerJudge::class);
            $judge->expects(self::once())->method('judge')->with($candidate)->willReturnCallback(static function (string $raw) use ($status): AnswerVerdict {
                if ($status === 'throw') {
                    throw new \RuntimeException('unavailable');
                }
                return new AnswerVerdict(
                    $status === 'stale' ? 'accepted' : $status,
                    hash('sha256', $status === 'stale' ? 'another candidate' : $raw),
                    'fixture',
                    ['criterion' => 'fixed']
                );
            });
            $loop = new AgentOrchestrator($llm, $tools, progressProbe: $probe, answerJudge: $judge);
            $answer = $loop->run('Diagnose');
            self::assertSame($end, $loop->termination()->reason);
            self::assertSame($stalled ? $receipt : null, $loop->termination()->receipt);
            self::assertSame(hash('sha256', $candidate), $loop->termination()->answerVerdict->candidateSha256);
            if ($end === RunEnd::FinalAnswer) {
                self::assertSame($candidate, $answer);
            } else {
                self::assertNotSame($candidate, $answer);
            }
        }
    }

    public function testInterruptionIsNotSwallowedByTheJudge(): void
    {
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturn(['role' => 'assistant', 'content' => 'answer']);
        $judge = $this->createMock(AnswerJudge::class);
        $judge->method('judge')->willThrowException(new RunInterrupted('stop'));
        $loop = new AgentOrchestrator($llm, $tools, answerJudge: $judge);
        try {
            $loop->run('Diagnose');
            self::fail('Expected interrupt');
        } catch (RunInterrupted) {
            self::assertSame(RunEnd::Interrupted, $loop->termination()->reason);
            self::assertNull($loop->termination()->answerVerdict);
        }
    }

    public function testVerdictRejectsMalformedProducerData(): void
    {
        foreach ([['yes', hash('sha256', 'x'), 'ok'], ['accepted', 'invalid', 'ok'], ['accepted', hash('sha256', 'x'), '']] as $args) {
            try {
                new AnswerVerdict(...$args);
                self::fail('Malformed verdict accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
