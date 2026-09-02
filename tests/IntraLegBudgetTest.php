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
use Milpa\AiGateway\PlanBoard;
use Milpa\AiGateway\ProgressProbe;

/**
 * The intra-leg budget's falsifiers (greenhouse fixture series, run 8).
 *
 * The measured killer: within ONE agent leg the outgoing projection grows ~3k tokens per step
 * (21,917 → 31,536 against qwen's 32,768 wall) because every step appends its tool results and
 * nothing governs the sum — the per-result bound (v0.14.1) caps each SINGLE result, the
 * WindowBudget governs BETWEEN legs. These tests encode the law of the fix: with a declared
 * context, every outgoing call's estimate stays under the leg budget by eliding the OLDEST
 * leg-internal tool results per-call — never mutating history, never touching the protected
 * set, and byte-identical when no context is declared.
 */
class IntraLegBudgetTest extends TestCase
{
    /**
     * The estimator the projection bound uses, mirrored here so the assertions measure with the
     * same ruler: serialized message chars over four.
     *
     * @param list<array<string, mixed>> $projection
     */
    private function estimate(array $projection): int
    {
        $chars = 0;
        foreach ($projection as $message) {
            $encoded = json_encode($message, JSON_UNESCAPED_UNICODE);
            $chars += mb_strlen($encoded === false ? '' : $encoded);
        }

        return intdiv($chars, 4);
    }

    /**
     * A scripted leg of `$fatSteps` tool steps, each returning a distinct ~6000-char result, then
     * a final answer. Returns every projection the LLM was called with.
     *
     * @return list<list<array<string, mixed>>> the captured per-call projections
     */
    private function runClimbingLeg(int $contextTokens, int $fatSteps, ?bool $useNamedZero = null): array
    {
        $llm = $this->createMock(LlmService::class);
        $mcp = $this->createMock(McpClientService::class);

        $mcp->method('getToolSummaries')->willReturn([
            ['name' => 'fat_tool', 'description' => 'returns a fat payload', 'inputSchema' => []],
        ]);

        $resultNo = 0;
        $mcp->method('callTool')->willReturnCallback(function () use (&$resultNo) {
            ++$resultNo;

            return "RESULT-{$resultNo}-" . str_repeat('x', 6000);
        });

        $captured = [];
        $call = 0;
        $llm->method('generateResponse')
            ->willReturnCallback(function ($prompt, $tools, $messages) use (&$captured, &$call, $fatSteps) {
                $captured[] = $messages;
                ++$call;
                if ($call <= $fatSteps) {
                    return [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            ['id' => "c{$call}", 'type' => 'function', 'function' => ['name' => 'fat_tool', 'arguments' => '{}']],
                        ],
                    ];
                }

                return ['role' => 'assistant', 'content' => 'done — the leg ends with a real answer'];
            });

        $orchestrator = new AgentOrchestrator(
            $llm,
            $mcp,
            $fatSteps + 5,
            null,
            null,
            null,
            false,
            null,
            $contextTokens
        );

        $orchestrator->run('climb');

        return $captured;
    }

    /**
     * Falsifier 1: with a declared context, a climbing leg's every outgoing call stays under the
     * leg budget; the OLDEST results are elided with a stub that names the tool and how to
     * recover the value; the newest four (the model's working set) ride untouched.
     */
    public function testAClimbingLegStaysUnderTheBudgetAndElidesOldestFirst(): void
    {
        $contextTokens = 16000;
        $budget = (int) floor($contextTokens * 0.75);

        $captured = $this->runClimbingLeg($contextTokens, 10);

        $this->assertCount(11, $captured, 'ten tool steps and the final call all went out');

        foreach ($captured as $n => $projection) {
            $this->assertLessThanOrEqual(
                $budget,
                $this->estimate($projection),
                "call {$n}'s outgoing estimate exceeds the leg budget — the climb was not governed"
            );
        }

        $last = $captured[10];
        $toolMessages = array_values(array_filter($last, fn ($m) => ($m['role'] ?? '') === 'tool'));
        $this->assertCount(10, $toolMessages, 'every tool result message is still present — elision shrinks, never removes');

        $oldest = $toolMessages[0];
        $this->assertStringContainsString('elided', (string) $oldest['content'], 'the oldest result was elided');
        $this->assertStringContainsString('fat_tool', (string) $oldest['content'], 'the stub names the tool to re-invoke');
        $this->assertStringNotContainsString('RESULT-1-', (string) $oldest['content'], 'the fat payload is gone from the stub');

        foreach (array_slice($toolMessages, -4) as $i => $recent) {
            $n = 7 + $i;
            $this->assertStringContainsString(
                "RESULT-{$n}-",
                (string) $recent['content'],
                "recent result {$n} (the model's working set) must never be elided"
            );
            $this->assertStringNotContainsString('elided to fit', (string) $recent['content']);
        }
    }

    /**
     * Falsifier 2: contextTokens = 0 is the byte-identical default — the message streams match a
     * construction that never heard of the parameter, and no stub appears anywhere.
     */
    public function testContextTokensZeroIsByteIdenticalToTheDefaultPath(): void
    {
        $withoutParam = $this->runClimbingLegLegacyShape(3);
        $withZero = $this->runClimbingLeg(0, 3);

        $this->assertSame(
            json_encode($withoutParam, JSON_UNESCAPED_UNICODE),
            json_encode($withZero, JSON_UNESCAPED_UNICODE),
            'contextTokens=0 must produce byte-identical projections to the pre-parameter construction'
        );

        foreach ($withZero as $projection) {
            foreach ($projection as $message) {
                if (($message['role'] ?? '') === 'tool') {
                    $this->assertStringNotContainsString('elided to fit', (string) $message['content']);
                }
            }
        }
    }

    /**
     * The same scripted leg as {@see runClimbingLeg} but constructed with the ORIGINAL eight
     * parameters — the golden A of the A/B.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function runClimbingLegLegacyShape(int $fatSteps): array
    {
        $llm = $this->createMock(LlmService::class);
        $mcp = $this->createMock(McpClientService::class);

        $mcp->method('getToolSummaries')->willReturn([
            ['name' => 'fat_tool', 'description' => 'returns a fat payload', 'inputSchema' => []],
        ]);

        $resultNo = 0;
        $mcp->method('callTool')->willReturnCallback(function () use (&$resultNo) {
            ++$resultNo;

            return "RESULT-{$resultNo}-" . str_repeat('x', 6000);
        });

        $captured = [];
        $call = 0;
        $llm->method('generateResponse')
            ->willReturnCallback(function ($prompt, $tools, $messages) use (&$captured, &$call, $fatSteps) {
                $captured[] = $messages;
                ++$call;
                if ($call <= $fatSteps) {
                    return [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            ['id' => "c{$call}", 'type' => 'function', 'function' => ['name' => 'fat_tool', 'arguments' => '{}']],
                        ],
                    ];
                }

                return ['role' => 'assistant', 'content' => 'done — the leg ends with a real answer'];
            });

        $orchestrator = new AgentOrchestrator($llm, $mcp, $fatSteps + 5, null, null, null, false, null);
        $orchestrator->run('climb');

        return $captured;
    }

    /**
     * Falsifier 3: pairing integrity — chat templates require every assistant `tool_calls` id to
     * keep its tool message in EVERY projection, even under a budget that elides everything
     * elidible. Elision shrinks content; it never removes a message or its `tool_call_id`.
     */
    public function testEveryToolCallIdKeepsItsToolMessageInEveryProjection(): void
    {
        $captured = $this->runClimbingLeg(100, 6);

        foreach ($captured as $n => $projection) {
            $wanted = [];
            $present = [];
            foreach ($projection as $message) {
                if (($message['role'] ?? '') === 'assistant' && !empty($message['tool_calls'])) {
                    foreach ($message['tool_calls'] as $tc) {
                        $wanted[] = $tc['id'];
                    }
                }
                if (($message['role'] ?? '') === 'tool' && isset($message['tool_call_id'])) {
                    $present[] = $message['tool_call_id'];
                }
            }
            foreach ($wanted as $id) {
                $this->assertContains(
                    $id,
                    $present,
                    "projection {$n}: assistant tool_calls id {$id} lost its tool message — the pairing broke"
                );
            }
        }
    }

    /**
     * Falsifier 4: the protected set never elides — system prompt, user turns, assistant turns,
     * the plan line and the last four tool results survive even a budget so small that
     * everything else elided.
     */
    public function testTheProtectedSetSurvivesABudgetThatElidesEverythingElse(): void
    {
        $llm = $this->createMock(LlmService::class);
        $mcp = $this->createMock(McpClientService::class);

        $mcp->method('getToolSummaries')->willReturn([
            ['name' => 'fat_tool', 'description' => 'returns a fat payload', 'inputSchema' => []],
        ]);

        $resultNo = 0;
        $mcp->method('callTool')->willReturnCallback(function () use (&$resultNo) {
            ++$resultNo;

            return "RESULT-{$resultNo}-" . str_repeat('x', 6000);
        });

        $plan = 'PLAN: keep climbing until the answer lands';
        $board = new class ($plan) implements PlanBoard {
            public function __construct(private string $plan)
            {
            }

            public function current(): ?string
            {
                return $this->plan;
            }
        };

        $captured = [];
        $call = 0;
        $llm->method('generateResponse')
            ->willReturnCallback(function ($prompt, $tools, $messages) use (&$captured, &$call) {
                $captured[] = $messages;
                ++$call;
                if ($call <= 6) {
                    return [
                        'role' => 'assistant',
                        'content' => "step {$call} narration",
                        'tool_calls' => [
                            ['id' => "c{$call}", 'type' => 'function', 'function' => ['name' => 'fat_tool', 'arguments' => '{}']],
                        ],
                    ];
                }

                return ['role' => 'assistant', 'content' => 'done — the leg ends with a real answer'];
            });

        // A context of 4 tokens: the leg budget rounds to ~3 — nothing fits, so everything
        // elidible MUST elide, and whatever still rides is the protected set by definition.
        $orchestrator = new AgentOrchestrator($llm, $mcp, 12, null, null, $board, false, null, 4);
        $orchestrator->run('climb', 'You are the falsifier system prompt.', [
            ['role' => 'user', 'content' => 'an earlier user turn'],
            ['role' => 'assistant', 'content' => 'an earlier assistant turn'],
        ]);

        $last = $captured[6];

        $this->assertSame('You are the falsifier system prompt.', $last[0]['content'], 'the system prompt never elides');
        $this->assertSame('an earlier user turn', $last[1]['content'], 'user turns never elide');
        $this->assertSame('an earlier assistant turn', $last[2]['content'], 'assistant turns never elide');
        $this->assertSame('climb', $last[3]['content'], 'the current user prompt never elides');

        $planLines = array_filter($last, fn ($m) => ($m['content'] ?? '') === $plan);
        $this->assertCount(1, $planLines, 'the plan line rides intact');

        foreach ($last as $message) {
            if (($message['role'] ?? '') === 'assistant') {
                $this->assertStringNotContainsString('elided to fit', (string) ($message['content'] ?? ''), 'assistant turns never elide');
            }
        }

        $toolMessages = array_values(array_filter($last, fn ($m) => ($m['role'] ?? '') === 'tool'));
        $this->assertCount(6, $toolMessages);
        foreach (\array_slice($toolMessages, 0, 2) as $old) {
            $this->assertStringContainsString('elided', (string) $old['content'], 'everything elidible elided under the tiny budget');
        }
        foreach (\array_slice($toolMessages, -4) as $i => $recent) {
            $n = 3 + $i;
            $this->assertStringContainsString(
                "RESULT-{$n}-",
                (string) $recent['content'],
                'the last four tool results are the working set — protected even when nothing fits'
            );
        }
    }

    /**
     * Falsifier 5: non-accumulation — elision is a per-call projection, `$messages` itself stays
     * untouched. A call fattened by a one-call stall notice elides the oldest result; the NEXT
     * call (notice gone, projection back under budget) sees that same result FULL again, because
     * it re-projects from the full history.
     */
    public function testElisionNeverAccumulatesIntoHistory(): void
    {
        $llm = $this->createMock(LlmService::class);
        $mcp = $this->createMock(McpClientService::class);

        $mcp->method('getToolSummaries')->willReturn([
            ['name' => 'fat_tool', 'description' => 'returns a fat payload', 'inputSchema' => []],
        ]);

        $resultNo = 0;
        $mcp->method('callTool')->willReturnCallback(function () use (&$resultNo) {
            ++$resultNo;

            return "RESULT-{$resultNo}-" . str_repeat('x', 6000);
        });

        // The probe stalls exactly once, after the fifth completed step, with a notice fat enough
        // to push THAT call over the budget. The notice rides one call only — the shrink back is
        // what exposes accumulation if the elision ever leaked into `$messages`.
        $probe = new class () implements ProgressProbe {
            public function afterStep(int $step): ?array
            {
                if ($step === 4) {
                    return ['stalled' => true, 'notice' => 'STALL NOTICE ' . str_repeat('N', 40000), 'receipt' => ['q' => 1]];
                }

                return null;
            }
        };

        $captured = [];
        $call = 0;
        $llm->method('generateResponse')
            ->willReturnCallback(function ($prompt, $tools, $messages) use (&$captured, &$call) {
                $captured[] = $messages;
                ++$call;
                if ($call <= 6) {
                    // Six tool steps — the sixth is the post-notice answer, and acting IS option A,
                    // so the leg continues past the forced choice.
                    return [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            ['id' => "c{$call}", 'type' => 'function', 'function' => ['name' => 'fat_tool', 'arguments' => '{}']],
                        ],
                    ];
                }

                return ['role' => 'assistant', 'content' => 'done — the leg ends with a real answer'];
            });

        $orchestrator = new AgentOrchestrator($llm, $mcp, 12, null, null, null, false, $probe, 16000);
        $orchestrator->run('climb');

        $this->assertCount(7, $captured, 'six tool steps and the final call all went out');

        // The notice-bearing call (index 5): over budget, the oldest result elided.
        $noticeCall = array_values(array_filter($captured[5], fn ($m) => ($m['role'] ?? '') === 'tool'));
        $this->assertStringContainsString('elided', (string) $noticeCall[0]['content'], 'the fat notice pushed the call over budget and the oldest result elided');

        // The next call (index 6): the notice is gone, the projection fits — the SAME result
        // rides full again, proof the elision never touched history.
        $afterCall = array_values(array_filter($captured[6], fn ($m) => ($m['role'] ?? '') === 'tool'));
        $this->assertStringContainsString(
            'RESULT-1-',
            (string) $afterCall[0]['content'],
            'the previously elided result must reappear FULL on the next projection — elision is per-call, never history'
        );
        $this->assertStringNotContainsString('elided to fit', (string) $afterCall[0]['content']);
    }
}
