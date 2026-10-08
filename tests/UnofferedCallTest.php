<?php

/**
 * A call the loop turns away because its tool was not offered in that step is told to the caller.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{AgentOrchestrator, LlmService, McpClientService};
use PHPUnit\Framework\TestCase;

/**
 * The call that was not offered (greenhouse decisions/0601, decided by Rod on 2026-10-08: it is recorded).
 *
 * When a model names a tool absent from the catalogue sent on that step, the loop answers it itself and never asks
 * the registry. Nobody else heard of it: a house that offers a session only what its seat can call kept no trace
 * that the session had tried something else (measured in greenhouse evidence/1163 §10 — a run where it could not be
 * known whether a resident had called a verb it built). The loop still answers it itself, with the same words; it
 * now also tells whoever asked to be told.
 *
 * @guards the caller is told the tool that was called, its arguments and the very answer the model reads, once per
 *         call and in order; the answer, the offer and the registry are untouched; with nobody to tell, and with
 *         a teller that fails, the run is the run it was
 *
 * @refuses a call that was offered being told as turned away; a teller that can change or fell the run
 */
final class UnofferedCallTest extends TestCase
{
    private const ANSWER = "Tool 'config_set' was not offered in this step. Choose from the current catalogue: make, route_observe.";

    /** @var list<array{string, array<string, mixed>}> */
    private array $called = [];
    /** @var list<list<array<string, mixed>>> */
    private array $seen = [];
    /** @var list<array{string, array<string, mixed>, string}> */
    private array $told = [];

    public function testTheCallerIsToldWhatWasCalledAndTheVeryAnswerTheModelReads(): void
    {
        $loop = $this->loop([self::calls(['config_set', '{"key":"a","value":1}']), 'Done.'])->setUnofferedCall($this->teller());

        self::assertSame('🔧 Done.', $loop->run('Change it.'));

        self::assertSame([['config_set', ['key' => 'a', 'value' => 1], self::ANSWER]], $this->told);
        self::assertSame([], $this->called, 'the registry was never asked');
        $answered = $this->seen[1][\count($this->seen[1]) - 1];
        self::assertSame(['tool', 'config_set', self::ANSWER], [$answered['role'], $answered['name'], $answered['content']], 'and the model reads that same answer');
    }

    public function testWithNobodyToTellTheRunIsTheRunItWas(): void
    {
        $told = $this->loop([self::calls(['config_set', '{"key":"a"}']), 'Done.'])->setUnofferedCall($this->teller());
        $told->run('Change it.');
        $withTeller = $this->seen;

        $silent = $this->loop([self::calls(['config_set', '{"key":"a"}']), 'Done.']);
        self::assertSame('🔧 Done.', $silent->run('Change it.'));

        self::assertSame($withTeller, $this->seen, 'every message the model was sent is the same, byte for byte');
        self::assertSame([], $this->called);
    }

    public function testACallThatWasOfferedIsNotTold(): void
    {
        $loop = $this->loop([self::calls(['make', '{"plugin":"Blog"}']), 'Done.'])->setUnofferedCall($this->teller());

        $loop->run('Build it.');

        self::assertSame([], $this->told);
        self::assertSame([['make', ['plugin' => 'Blog']]], $this->called);
    }

    public function testEachCallOfAStepIsToldOnceAndInOrderAndTheOfferedOneStillRuns(): void
    {
        $loop = $this->loop([self::calls(['config_set', '{}'], ['make', '{}'], ['identity_enroll', '{"key":"k"}']), 'Done.'])->setUnofferedCall($this->teller());

        $loop->run('Do all three.');

        self::assertSame(['config_set', 'identity_enroll'], array_column($this->told, 0));
        self::assertSame([[], ['key' => 'k']], array_column($this->told, 1));
        self::assertSame([['make', []]], $this->called);
    }

    public function testATellerThatFailsNeitherFellsTheRunNorChangesTheAnswer(): void
    {
        $loop = $this->loop([self::calls(['config_set', '{}']), 'Done.'])->setUnofferedCall(static function (): void {
            throw new \RuntimeException('the ledger is not writable');
        });

        self::assertSame('🔧 Done.', $loop->run('Change it.'));

        $answered = $this->seen[1][\count($this->seen[1]) - 1];
        self::assertSame(self::ANSWER, $answered['content']);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function argumentsThatAreNoObject(): iterable
    {
        yield 'no arguments' => [''];
        yield 'null' => ['null'];
        yield 'a bare scalar' => ['7'];
        yield 'not JSON' => ['{"key":'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('argumentsThatAreNoObject')]
    public function testArgumentsThatAreNoObjectAreToldAsNone(mixed $raw): void
    {
        $loop = $this->loop([self::calls(['config_set', $raw]), 'Done.'])->setUnofferedCall($this->teller());

        $loop->run('Change it.');

        self::assertSame([['config_set', [], self::ANSWER]], $this->told);
    }

    public function testNullWithdrawsIt(): void
    {
        $loop = $this->loop([self::calls(['config_set', '{}']), 'Done.'])->setUnofferedCall($this->teller())->setUnofferedCall(null);

        $loop->run('Change it.');

        self::assertSame([], $this->told);
    }

    private function teller(): \Closure
    {
        $this->told = [];

        return function (string $tool, array $arguments, string $answer): void {
            $this->told[] = [$tool, $arguments, $answer];
        };
    }

    /**
     * A loop whose model answers the script, in order, offered two tools, and whose executor remembers what it ran.
     *
     * @param list<array<string, mixed>|string> $script
     */
    private function loop(array $script): AgentOrchestrator
    {
        $this->called = [];
        $this->seen = [];
        $executor = $this->createMock(McpClientService::class);
        $executor->method('getToolSummaries')->willReturn([self::tool('make'), self::tool('route_observe')]);
        $executor->method('callTool')->willReturnCallback(function (string $name, array $arguments): string {
            $this->called[] = [$name, $arguments];

            return 'ran ' . $name;
        });
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willReturnCallback(function (string $prompt, array $tools, array $messages) use (&$script): array {
            $this->seen[] = $messages;
            $next = array_shift($script);

            return \is_string($next) ? ['role' => 'assistant', 'content' => $next] : $next;
        });

        return new AgentOrchestrator($llm, $executor, 10);
    }

    /** @return array<string, mixed> */
    private static function tool(string $name): array
    {
        return ['name' => $name, 'description' => $name, 'inputSchema' => ['type' => 'object', 'properties' => (object) []]];
    }

    /**
     * One assistant message with these calls: each a name and its arguments as the provider sent them.
     *
     * @param array{0: string, 1: mixed} ...$calls
     *
     * @return array<string, mixed>
     */
    private static function calls(array ...$calls): array
    {
        $toolCalls = [];
        foreach ($calls as $n => [$name, $raw]) {
            $toolCalls[] = ['id' => 'm' . $n, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $raw]];
        }

        return ['role' => 'assistant', 'content' => '', 'tool_calls' => $toolCalls];
    }
}
