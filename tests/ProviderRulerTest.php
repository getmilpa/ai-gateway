<?php

/**
 * This file is part of Milpa AI Gateway — the LLM gateway and native
 * tool-use runtime for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\RunEnd;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The window the house knows is the window it obeys (greenhouse decisions/0514, evidence/1036).
 *
 * evidence/1036 measured a house that knew its model's window (n_ctx 49,152, asked of the
 * provider) and still sent 49,582 and 50,772 tokens into it: the chars/4 estimate under-counted
 * the provider by a third, an undeclared output limit reserved nothing, and the heal resent the
 * identical request twice. The fake provider here counts like that one — 1.5 of its tokens per
 * estimated token — and answers llama.cpp's own 400 past its window.
 *
 * @internal
 */
final class ProviderRulerTest extends TestCase
{
    private const WINDOW = 12000;

    /** Provider tokens per estimated token: the under-count evidence/1036 measured, rounded. */
    private const PROVIDER_RATIO = 1.5;

    /**
     * A provider that counts 1.5 tokens per estimated token, reports that count as usage, and
     * refuses past its window with the llama.cpp body — recording every request and its count.
     *
     * @param list<array<string, mixed>> $script   the assistant messages to answer, in order
     * @param list<int>                  $counts   out-param: the provider count of every request
     * @param list<int>                  $statuses out-param: every status answered
     * @param list<int>                  $limits   out-param: the output limit every request asked for
     */
    private function provider(array $script, ?array &$counts, ?array &$statuses, ?array &$limits): ClientInterface
    {
        $counts = [];
        $statuses = [];
        $limits = [];

        return new class ($script, $counts, $statuses, $limits) implements ClientInterface {
            private int $answered = 0;

            /** @param list<array<string, mixed>> $script */
            public function __construct(private array $script, private array &$counts, private array &$statuses, private array &$limits)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $payload = json_decode((string) $request->getBody(), true);
                $chars = mb_strlen((string) json_encode($payload['messages'] ?? [], JSON_UNESCAPED_UNICODE))
                    + mb_strlen((string) json_encode($payload['tools'] ?? [], JSON_UNESCAPED_UNICODE));
                $count = (int) ceil($chars / 4 * ProviderRulerTest::providerRatio());
                $this->counts[] = $count;
                $this->limits[] = (int) ($payload['max_completion_tokens'] ?? 0);

                if ($count > ProviderRulerTest::window()) {
                    $this->statuses[] = 400;

                    return new Response(400, [], (string) json_encode(['error' => [
                        'code' => 400,
                        'message' => "request ({$count} tokens) exceeds the available context size (" . ProviderRulerTest::window() . ' tokens), try increasing it',
                        'type' => 'exceed_context_size_error',
                        'n_prompt_tokens' => $count,
                        'n_ctx' => ProviderRulerTest::window(),
                    ]]));
                }

                $message = $this->script[$this->answered] ?? ['role' => 'assistant', 'content' => 'The work is done.'];
                ++$this->answered;
                $this->statuses[] = 200;

                return new Response(200, [], (string) json_encode([
                    'choices' => [['message' => $message, 'finish_reason' => isset($message['tool_calls']) ? 'tool_calls' : 'stop']],
                    'usage' => ['prompt_tokens' => $count, 'completion_tokens' => 10, 'total_tokens' => $count + 10],
                ]));
            }
        };
    }

    /** The fake provider's window, readable from its anonymous class. */
    public static function window(): int
    {
        return self::WINDOW;
    }

    /** The fake provider's ruler, readable from its anonymous class. */
    public static function providerRatio(): float
    {
        return self::PROVIDER_RATIO;
    }

    /**
     * A leg of `$steps` tool calls, each result ~`$resultChars` characters, then a final answer.
     *
     * @return array{AgentOrchestrator, string}
     */
    private function leg(ClientInterface $provider, int $steps, int $resultChars, ?int $outputTokens = null, string $system = 'You read files.'): array
    {
        $llm = new LlmService('key', 'qwen', 'openai', null, $provider);
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([
            ['name' => 'read', 'description' => 'Read one file', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
        ]);
        $n = 0;
        $mcp->method('callTool')->willReturnCallback(static function () use (&$n, $resultChars): string {
            ++$n;

            return str_repeat("file {$n} line ", intdiv($resultChars, 14));
        });
        $orchestrator = new AgentOrchestrator($llm, $mcp, $steps + 3, null, null, null, false, null, self::WINDOW, outputTokens: $outputTokens);

        return [$orchestrator, $orchestrator->run('Read the files, then report.', $system)];
    }

    /** @return list<array<string, mixed>> */
    private function readSteps(int $steps): array
    {
        $script = [];
        for ($i = 1; $i <= $steps; ++$i) {
            $script[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => "read-{$i}", 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{}']],
            ]];
        }

        return $script;
    }

    /**
     * Once the provider has counted one request, every later request of the leg is bounded in
     * the PROVIDER's tokens: none reaches the window, and none is refused — where the chars/4
     * ruler alone lets the climb run into the wall (the mutation control below).
     */
    public function testTheProviderCountCalibratesEveryLaterRequestOfTheLeg(): void
    {
        $provider = $this->provider($this->readSteps(8), $counts, $statuses, $limits);
        [$loop, $answer] = $this->leg($provider, 8, 6000);

        self::assertStringEndsWith('The work is done.', $answer);
        self::assertNotContains(400, $statuses, 'no request past the window is ever sent');
        foreach (\array_slice($counts, 1) as $count) {
            self::assertLessThanOrEqual((int) floor(self::WINDOW * 0.75), $count, 'bounded to the leg budget in provider tokens');
        }
        self::assertSame(RunEnd::FinalAnswer, $loop->termination()?->reason);
    }

    /**
     * The same undeclared output limit the request carries is the room every request keeps: a
     * known window of 12,000 tokens asks for (and reserves) a quarter of it, never 4096 on top of
     * an input that already fills the window.
     */
    public function testAnUndeclaredLimitOnAKnownWindowReservesWhatItRequests(): void
    {
        $provider = $this->provider($this->readSteps(3), $counts, $statuses, $limits);
        $this->leg($provider, 3, 2000);

        self::assertSame([3000], array_values(array_unique($limits)), 'requested = reserved = a quarter of a small window');
        foreach ($counts as $count) {
            self::assertLessThanOrEqual(self::WINDOW - 3000, $count);
        }
    }

    /**
     * A request the protected set alone makes too big, AFTER a completed step and a calibrated
     * ruler, is not sent: the leg pauses honestly (the same pause a declared limit gets), with
     * the provider's count in the receipt — instead of a 400 and a `failed` leg.
     */
    public function testAnUnshrinkableRequestAfterAStepPausesBeforeEgress(): void
    {
        // A protected system prompt of ~8.9k provider tokens fits the first request; one step's
        // result (protected too: the newest results never elide) pushes the next past 9,000.
        $provider = $this->provider($this->readSteps(1), $counts, $statuses, $limits);
        [$loop, $answer] = $this->leg($provider, 1, 8000, system: str_repeat('rule ', 4700));

        self::assertSame(AgentOrchestrator::CONTEXT_BUDGET_EXHAUSTED, $answer);
        self::assertSame(RunEnd::ContextBudgetExhausted, $loop->termination()?->reason);
        self::assertCount(1, $counts, 'the oversized second request never left');
        self::assertNotContains(400, $statuses);
        self::assertGreaterThan(self::WINDOW - 3000, $loop->termination()->receipt['estimatedInputTokens']);
    }

    /** A provider that reports no usage calibrates nothing: the leg runs as it always has. */
    public function testWithoutAProviderCountNothingIsRefusedOnAGuess(): void
    {
        $sent = 0;
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(function () use (&$sent): Response {
            ++$sent;

            return new Response(200, [], (string) json_encode(['choices' => [['message' => $sent < 3
                ? ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => "r{$sent}", 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{}']]]]
                : ['role' => 'assistant', 'content' => 'Answered without a count.'], 'finish_reason' => 'stop']]]));
        });
        [, $answer] = $this->leg($http, 2, 3000);

        self::assertStringEndsWith('Answered without a count.', $answer);
        self::assertSame(3, $sent);
    }

    /** The count describes the LAST call only: a new call forgets it until the provider speaks. */
    public function testLastUsageIsTheProviderCountOfTheLatestCallOnly(): void
    {
        $answers = [
            new Response(200, [], (string) json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'one']]], 'usage' => ['prompt_tokens' => 321, 'completion_tokens' => 4]])),
            new Response(200, [], (string) json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'two']]]])),
        ];
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnOnConsecutiveCalls(...$answers);
        $llm = new LlmService('key', 'qwen', 'openai', null, $http);

        self::assertNull($llm->lastUsage());
        $llm->generateResponse('first');
        self::assertSame(321, $llm->lastUsage()['prompt_tokens'] ?? null);
        $llm->generateResponse('second');
        self::assertNull($llm->lastUsage(), 'a call that reported no usage leaves no stale count behind');
    }
}
