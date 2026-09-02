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
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\ProgressProbe;
use Psr\Log\LoggerInterface;

/**
 * The forced choice (greenhouse decisions/0185): when the probe says the run stalled, the model
 * gets the notice ONCE, and its next answer must act, declare `HOUSE_DEBT:`, or `ABANDON:` the
 * hypothesis — anything else ends the leg with the stalled sentinel. What these falsifiers make
 * impossible is option E: another eight thousand tokens of thinking about how to write a test
 * (the fifth run's measured pattern, frozen at greenhouse
 * `evidence/fixtures/corrida5-work-mthqbzu6`).
 */
class ForcedChoiceTest extends TestCase
{
    private const NOTICE = 'No semantic progress in 4 calls. Choose: (A) execute an operation that '
        . 'produces evidence, (B) declare HOUSE_DEBT: <digest>, (C) ask the human, or (D) ABANDON: '
        . '<hypothesis>.';

    private LlmService $llmService;
    private McpClientService $mcpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmService = $this->createMock(LlmService::class);
        $this->mcpClient = $this->createMock(McpClientService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /** A probe that always answers the same opinion, remembering which steps it was asked about. */
    private function probeAnswering(?array $answer, array &$stepsAsked): ProgressProbe
    {
        return new class ($answer, $stepsAsked) implements ProgressProbe {
            /** @var list<int> */
            private array $stepsAsked;

            /** @param list<int> $stepsAsked */
            public function __construct(private readonly ?array $answer, array &$stepsAsked)
            {
                $this->stepsAsked = &$stepsAsked;
            }

            public function afterStep(int $step): ?array
            {
                $this->stepsAsked[] = $step;

                return $this->answer;
            }
        };
    }

    private function orchestratorWith(?ProgressProbe $probe, int $maxSteps = 10): AgentOrchestrator
    {
        return new AgentOrchestrator(
            $this->llmService,
            $this->mcpClient,
            $maxSteps,
            $this->logger,
            null,
            null,
            false,
            $probe,
        );
    }

    /** @return array<string, mixed> a scripted assistant turn calling one read-only tool */
    private static function toolCallTurn(string $id = 'c1'): array
    {
        return [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [
                ['id' => $id, 'function' => ['name' => 'observe', 'arguments' => '{}']],
            ],
        ];
    }

    private function toolsOnTheTable(): void
    {
        $this->mcpClient->method('getToolSummaries')->willReturn([
            ['name' => 'observe', 'description' => 'read-only observation', 'inputSchema' => []],
        ]);
        $this->mcpClient->method('callTool')->willReturn('{"ok":true}');
    }

    /**
     * A stalled probe injects the notice into the NEXT call's messages exactly once — one system
     * line, never accumulated into the running history (the plan's own non-accumulation doctrine).
     */
    public function testAStalledProbeInjectsTheNoticeExactlyOnceForTheNextCall(): void
    {
        $this->toolsOnTheTable();

        $asked = [];
        $probe = $this->probeAnswering(
            ['stalled' => true, 'notice' => self::NOTICE, 'receipt' => ['calls' => 4]],
            $asked,
        );

        $seen = [];
        $this->llmService->method('generateResponse')
            ->willReturnCallback(function (string $p, array $t, array $messages) use (&$seen): array {
                $seen[] = $messages;

                return \count($seen) === 1
                    ? self::toolCallTurn()
                    : ['role' => 'assistant', 'content' => 'HOUSE_DEBT: judge-target deadlock, framework-owned'];
            });

        $this->orchestratorWith($probe)->run('close the deadlock');

        self::assertSame([0], $asked, 'the probe was consulted after the completed step, once');

        $notices = static fn (array $messages): array => array_values(array_filter(
            $messages,
            // user role, not system: providers reject non-leading system messages (the measured
            // qwen Jinja 500) — the notice is a steering line, and steering rides as user.
            static fn (array $m): bool => ($m['role'] ?? '') === 'user'
                && str_contains((string) ($m['content'] ?? ''), 'No semantic progress'),
        ));

        self::assertCount(0, $notices($seen[0]), 'no notice before the probe spoke');
        self::assertCount(1, $notices($seen[1]), 'the next call carries the notice exactly once');
        self::assertSame(self::NOTICE, $notices($seen[1])[0]['content']);
    }

    /**
     * THE ENFORCEMENT: post-notice philosophy — content only, no tool calls, no marker — ends the
     * leg immediately with the stalled sentinel, the receipt attached. This is what makes option E
     * impossible: the loop refuses to buy another call of thinking.
     */
    public function testPostNoticePhilosophyEndsTheLegWithTheStalledSentinel(): void
    {
        $this->toolsOnTheTable();

        $asked = [];
        $probe = $this->probeAnswering(
            ['stalled' => true, 'notice' => self::NOTICE, 'receipt' => ['calls' => 4, 'newEvidence' => 0]],
            $asked,
        );

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                self::toolCallTurn(),
                ['role' => 'assistant', 'content' => 'Perhaps the deadlock could be reframed as a lifecycle question…'],
            );

        $result = $this->orchestratorWith($probe)->run('close the deadlock');

        self::assertStringStartsWith(AgentOrchestrator::PROGRESS_STALLED, $result, 'the sentinel names the end');
        self::assertStringContainsString('"newEvidence":0', $result, 'the receipt travels in the answer');
    }

    /** A post-notice response WITH tool calls proceeds normally — acting IS option A. */
    public function testAPostNoticeToolCallProceedsNormally(): void
    {
        $this->toolsOnTheTable();

        $stall = ['stalled' => true, 'notice' => self::NOTICE, 'receipt' => ['calls' => 4]];
        $answers = [$stall, null, null];
        $asked = [];
        $probe = new class ($answers, $asked) implements ProgressProbe {
            /** @var list<int> */
            private array $asked;

            /** @param list<?array<string, mixed>> $answers */
            public function __construct(private array $answers, array &$asked)
            {
                $this->asked = &$asked;
            }

            public function afterStep(int $step): ?array
            {
                $this->asked[] = $step;

                return array_shift($this->answers);
            }
        };

        $this->llmService->expects($this->exactly(3))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                self::toolCallTurn('c1'),
                self::toolCallTurn('c2'),
                ['role' => 'assistant', 'content' => 'The deadlock is closed; the receipt says so.'],
            );

        $result = $this->orchestratorWith($probe)->run('close the deadlock');

        self::assertStringContainsString('The deadlock is closed', $result, 'acting satisfied the choice');
        self::assertStringNotContainsString(AgentOrchestrator::PROGRESS_STALLED, $result);
    }

    /** `HOUSE_DEBT:` ends the leg returning that content verbatim — the caller records the debt. */
    public function testHouseDebtEndsTheLegVerbatim(): void
    {
        $this->toolsOnTheTable();

        $asked = [];
        $probe = $this->probeAnswering(
            ['stalled' => true, 'notice' => self::NOTICE, 'receipt' => ['calls' => 4]],
            $asked,
        );

        $declaration = 'HOUSE_DEBT: the judge cannot verify a target that boots the judge — framework-owned plumbing.';
        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                self::toolCallTurn(),
                ['role' => 'assistant', 'content' => $declaration],
            );

        $result = $this->orchestratorWith($probe)->run('close the deadlock');

        self::assertSame($declaration, $result, 'verbatim: no prefix, no wrapper, no sentinel');
    }

    /** `ABANDON:` is appended as the assistant turn and the loop CONTINUES — work goes on. */
    public function testAbandonContinuesTheLoop(): void
    {
        $this->toolsOnTheTable();

        $stall = ['stalled' => true, 'notice' => self::NOTICE, 'receipt' => ['calls' => 4]];
        $answers = [$stall, null];
        $probe = new class ($answers) implements ProgressProbe {
            /** @param list<?array<string, mixed>> $answers */
            public function __construct(private array $answers)
            {
            }

            public function afterStep(int $step): ?array
            {
                return array_shift($this->answers);
            }
        };

        $abandon = 'ABANDON: patching the judge in place — the fixture route is the real path.';
        $seen = [];
        $this->llmService->expects($this->exactly(3))
            ->method('generateResponse')
            ->willReturnCallback(function (string $p, array $t, array $messages) use (&$seen, $abandon): array {
                $seen[] = $messages;

                return match (\count($seen)) {
                    1 => self::toolCallTurn(),
                    2 => ['role' => 'assistant', 'content' => $abandon],
                    default => ['role' => 'assistant', 'content' => 'Built it through the fixture route instead.'],
                };
            });

        $result = $this->orchestratorWith($probe)->run('close the deadlock');

        self::assertStringContainsString('fixture route instead', $result, 'the run outlived the abandoned hypothesis');
        $lastSeen = $seen[2];
        $abandonTurns = array_values(array_filter(
            $lastSeen,
            static fn (array $m): bool => ($m['role'] ?? '') === 'assistant'
                && str_starts_with(trim((string) ($m['content'] ?? '')), 'ABANDON:'),
        ));
        self::assertCount(1, $abandonTurns, 'the abandonment stays in history as the assistant turn it was');
    }

    /**
     * A null probe is byte-identical to no probe at all: same messages to the model, same answer.
     * This is the golden falsifier that keeps the default path exactly what origin/main shipped —
     * the rest of this suite's untouched tests are its other half.
     */
    public function testANullProbeIsByteIdenticalToNoProbe(): void
    {
        self::assertSame(
            $this->messagesOfOneRun(withProbe: false),
            $this->messagesOfOneRun(withProbe: true),
        );
    }

    /**
     * Precedence, documented by test: post-notice, the stall enforcement runs BEFORE the
     * degenerate-answer guard (0.15.0). A degenerate answer after the notice is neither a tool
     * call nor a marker, so the leg ends with the stalled sentinel after exactly two model calls —
     * the guard's retry would be precisely the extra thinking the enforcement exists to refuse.
     */
    public function testAStallNoticePlusADegenerateAnswerResolvesToTheStalledSentinelWithoutARetry(): void
    {
        $this->toolsOnTheTable();

        $asked = [];
        $probe = $this->probeAnswering(
            ['stalled' => true, 'notice' => self::NOTICE, 'receipt' => ['calls' => 4]],
            $asked,
        );

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                self::toolCallTurn(),
                ['role' => 'assistant', 'content' => '🔧 ', 'reasoning_content' => str_repeat('r', 47591)],
            );

        $result = $this->orchestratorWith($probe)->run('close the deadlock');

        self::assertStringStartsWith(AgentOrchestrator::PROGRESS_STALLED, $result);
    }

    /** A probe that throws never takes the run down: observation must not break the observed. */
    public function testAProbeThatThrowsDoesNotBreakTheRun(): void
    {
        $this->toolsOnTheTable();

        $probe = new class () implements ProgressProbe {
            public function afterStep(int $step): ?array
            {
                throw new \RuntimeException('the stream reader died');
            }
        };

        $this->llmService->expects($this->exactly(2))
            ->method('generateResponse')
            ->willReturnOnConsecutiveCalls(
                self::toolCallTurn(),
                ['role' => 'assistant', 'content' => 'the answer survived the probe'],
            );

        $result = $this->orchestratorWith($probe)->run('do the thing');

        self::assertStringContainsString('the answer survived the probe', $result);
    }

    /**
     * The messages one healthy scripted run put in front of the model, with or without a probe
     * that has no opinion.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function messagesOfOneRun(bool $withProbe): array
    {
        $llm = $this->createMock(LlmService::class);
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([
            ['name' => 'observe', 'description' => 'read-only observation', 'inputSchema' => []],
        ]);
        $mcp->method('callTool')->willReturn('{"ok":true}');

        $seen = [];
        $llm->method('generateResponse')
            ->willReturnCallback(function (string $p, array $t, array $messages) use (&$seen): array {
                $seen[] = $messages;

                return \count($seen) === 1
                    ? self::toolCallTurn()
                    : ['role' => 'assistant', 'content' => 'done'];
            });

        $asked = [];
        $probe = $withProbe ? $this->probeAnswering(null, $asked) : null;
        (new AgentOrchestrator($llm, $mcp, 10, null, null, null, false, $probe))->run('hola');

        return $seen;
    }
}
