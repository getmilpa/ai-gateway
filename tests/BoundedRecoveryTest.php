<?php

/**
 * This file is part of Milpa AI Gateway.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\ProgressProbe;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @internal */
final class BoundedRecoveryTest extends TestCase
{
    /** @return array{stalled: bool, notice: string, receipt: array<string, mixed>, recovery: string} */
    private static function observation(string $state): array
    {
        return ['stalled' => $state !== 'recovered', 'notice' => $state === 'recovered' ? '' : 'Measured recovery pending.',
            'receipt' => ['window' => $state], 'recovery' => $state];
    }

    /** @return iterable<string, array{list<mixed>, int, bool}> */
    public static function recoverySequences(): iterable
    {
        $pending = self::observation('pending');
        $recovered = self::observation('recovered');
        $exhausted = self::observation('exhausted');
        yield 'tool success and silence do not clear recovery' => [[$pending, null], 3, true];
        yield 'failed observer does not clear recovery' => [[$pending, new \RuntimeException('offline')], 3, true];
        yield 'legacy false is not an explicit recovery' => [[$pending, ['stalled' => false, 'notice' => '', 'receipt' => []]], 3, true];
        yield 'preparation followed by evidence' => [[$pending, $pending, $recovered], 4, false];
        yield 'observation resumes after silence' => [[$pending, null, $recovered], 4, false];
        yield 'exhaustion stops before another model call' => [[$pending, $exhausted], 2, true];
        yield 'a new stall after recovery gets its own preparation' => [[$pending, $recovered, $pending, $pending, $recovered], 6, false];
    }

    /** @param list<mixed> $answers */
    #[DataProvider('recoverySequences')]
    public function testRecoveryRequiresAnExplicitObservation(array $answers, int $expectedCalls, bool $stopped): void
    {
        $probe = new class ($answers) implements ProgressProbe {
            /** @param list<mixed> $answers */
            public function __construct(private array $answers)
            {
            }
            public function afterStep(int $step): ?array
            {
                $answer = array_shift($this->answers);
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }
                return $answer;
            }
        };
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'prepare', 'description' => 'prepare work', 'inputSchema' => []]]);
        $tools->method('callTool')->willReturn('{"ok":true}');
        $llm = $this->createMock(LlmService::class);
        $step = 0;
        $scriptLength = count($answers);
        $llm->expects(self::exactly($expectedCalls))->method('generateResponse')->willReturnCallback(
            static function () use (&$step, $scriptLength): array {
                if ($step++ >= $scriptLength) {
                    return ['role' => 'assistant', 'content' => 'The work is finished.'];
                }
                return ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                    ['id' => 'c' . $step, 'function' => ['name' => 'prepare', 'arguments' => '{}']],
                ]];
            },
        );
        $result = (new AgentOrchestrator($llm, $tools, maxSteps: 48, progressProbe: $probe))->run('Build the app.');
        self::assertSame($stopped, str_starts_with($result, AgentOrchestrator::PROGRESS_STALLED));
        if (!$stopped) {
            self::assertStringContainsString('The work is finished.', $result);
        }
    }
    /** A declared abandonment cannot replenish a measured recovery window. */
    public function testRepeatedAbandonmentCannotResetRecovery(): void
    {
        $answers = [self::observation('pending'), self::observation('exhausted')];
        $probe = new class ($answers) implements ProgressProbe {
            /** @param list<array<string, mixed>> $answers */
            public function __construct(private array $answers)
            {
            }
            public function afterStep(int $step): ?array
            {
                return array_shift($this->answers);
            }
        };
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'prepare', 'description' => 'prepare', 'inputSchema' => []]]);
        $tools->method('callTool')->willReturn('{"ok":true}');
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::exactly(2))->method('generateResponse')->willReturnOnConsecutiveCalls(
            ['role' => 'assistant', 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'prepare', 'arguments' => '{}']]]],
            ['role' => 'assistant', 'content' => 'ABANDON: the old approach'],
        );
        $result = (new AgentOrchestrator($llm, $tools, progressProbe: $probe))->run('Build UI.');
        self::assertStringStartsWith(AgentOrchestrator::PROGRESS_STALLED, $result);
        self::assertStringContainsString('exhausted', $result);
    }

    /** A real confirmation request retains its early exit while recovery is pending. */
    public function testConfirmationStillReturnsToItsCaller(): void
    {
        $probe = new class (self::observation('pending')) implements ProgressProbe {
            /** @param array<string, mixed> $answer */
            public function __construct(private array $answer)
            {
            }
            public function afterStep(int $step): ?array
            {
                return $this->answer;
            }
        };
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'prepare', 'description' => 'prepare', 'inputSchema' => []]]);
        $tools->method('callTool')->willReturnOnConsecutiveCalls('{"ok":true}', ['requires_confirmation' => true, 'message' => 'Confirm the edit.']);
        $llm = $this->createMock(LlmService::class);
        $llm->expects(self::exactly(2))->method('generateResponse')->willReturn(
            ['role' => 'assistant', 'tool_calls' => [['id' => 'c', 'function' => ['name' => 'prepare', 'arguments' => '{}']]]],
        );
        $result = (new AgentOrchestrator($llm, $tools, progressProbe: $probe))->run('Build UI.');
        self::assertStringContainsString('Confirm the edit.', $result);
        self::assertStringNotContainsString(AgentOrchestrator::PROGRESS_STALLED, $result);
    }

}
