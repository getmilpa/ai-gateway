<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\{AgentOrchestrator,InputBudgetExceededException,InputBudgetUnavailableException,LlmService,McpClientService};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/** A local count refusal is not a provider error or a successful fallback answer. @internal */
final class CountedInputBudgetTest extends TestCase
{
    public static function paths(): iterable
    {
        foreach (['openai-buffered', 'openai-streamed', 'anthropic-buffered'] as $surface) {
            foreach ([
                'initial' => [['exceeded'], 'failed'],
                'after-tool' => [['tool', 'exceeded'], 'context_budget_exhausted'],
                'unknown-initial' => [['unavailable'], 'failed'],
                'unknown-after-tool' => [['tool', 'unavailable'], 'failed'],
                'http-heal-initial' => [['http400', 'exceeded'], 'failed'],
                'http-heal-after-tool' => [['tool', 'http400', 'exceeded'], 'context_budget_exhausted'],
                'http-heal-unknown' => [['http400', 'unavailable'], 'failed'],
                'boundary-fits' => [['answer'], 'final_answer'],
            ] as $path => [$flow, $end]) {
                yield $surface . '/' . $path => [$surface, $flow, $end];
            }
            if (str_starts_with($surface, 'openai')) {
                foreach (['exceeded', 'unavailable'] as $refusal) {
                    yield $surface . '/degenerate-' . $refusal => [$surface, ['degenerate', $refusal], 'failed'];
                }
            }
        }
    }

    #[DataProvider('paths')]
    public function testGuardAcrossNativePaths(string $surface, array $flow, string $expectedEnd): void
    {
        [$loop, $facts] = $this->loop($surface, $flow);
        $error = null;
        try {
            $answer = $loop->run('Inspect once, then report.');
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        $end = $loop->termination()->toArray();
        self::assertSame($expectedEnd, $end['reason']);
        self::assertSame(count($flow), $facts->attempts, 'No retry follows a local refusal.');
        self::assertSame(count(array_filter($flow, fn ($v) => !in_array($v, ['exceeded', 'unavailable']))), $facts->dispatches);
        if ($expectedEnd === 'failed') {
            self::assertSame($facts->refusal, $error, 'The exact typed exception survives streaming and recovery.');
            self::assertSame($error->receipt(), $end['receipt']);
        } elseif ($expectedEnd === 'context_budget_exhausted') {
            self::assertNull($error);
            self::assertSame(AgentOrchestrator::CONTEXT_BUDGET_EXHAUSTED, $answer);
            self::assertSame(1, $end['receipt']['completedSteps']);
            self::assertSame($facts->refusal->receipt(), array_intersect_key($end['receipt'], $facts->refusal->receipt()));
        } else {
            self::assertNull($error);
            self::assertStringEndsWith('Inspection completed.', $answer);
        }
    }

    public function testMismatchedOrUnknownLimitsCannotBecomeAContinuablePause(): void
    {
        foreach ([[0, 8192], [32768, 4096], [65536, 8192]] as [$context, $output]) {
            [$loop, $facts] = $this->loop('openai-buffered', ['tool', 'exceeded'], $context, $output);
            try {
                $loop->run('Inspect once.');
                self::fail('Mismatched counter was accepted as a pause.');
            } catch (InputBudgetExceededException $error) {
                self::assertSame($facts->refusal, $error);
                self::assertSame('failed', $loop->termination()->reason->value);
                self::assertSame(1, $facts->dispatches);
            }
        }
    }

    public function testNoGuardKeepsTheOriginalRequestAndAnswer(): void
    {
        foreach (['openai-buffered', 'openai-streamed', 'anthropic-buffered'] as $surface) {
            [$counted, $with] = $this->loop($surface, ['tool', 'answer']);
            [$legacy, $without] = $this->loop($surface, ['tool', 'answer'], guard: false);
            self::assertSame($counted->run('Inspect once.'), $legacy->run('Inspect once.'));
            self::assertSame($with->bodies, $without->bodies);
            self::assertSame($counted->termination()->toArray(), $legacy->termination()->toArray());
            self::assertSame(0, $without->counts);
        }
    }

    public function testInvalidCountReceiptsCannotMasqueradeAsOverflow(): void
    {
        $hash = hash('sha256', 'body');
        foreach ([[0,32768,8192,$hash], [24576,32768,8192,$hash], [-1,32768,8192,$hash],
            [24577,0,8192,$hash], [24577,32768,0,$hash], [24577,32768,32768,$hash], [24577,32768,8192,'bad']] as $args) {
            try {
                new InputBudgetExceededException(...$args);
                self::fail('Invalid counted reserve accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $previous = new \RuntimeException('Counter unavailable.');
        $error = new InputBudgetUnavailableException($hash, $previous);
        self::assertSame($previous, $error->getPrevious());
        self::assertArrayNotHasKey('inputTokens', $error->receipt());
        $this->expectException(\InvalidArgumentException::class);
        new InputBudgetUnavailableException('not-a-hash');
    }

    private function loop(string $surface, array $flow, int $context = 32768, int $output = 8192, bool $guard = true): array
    {
        $provider = str_starts_with($surface, 'anthropic') ? 'anthropic' : 'openai';
        $stream = str_ends_with($surface, 'streamed');
        $facts = (object) ['attempts' => 0, 'dispatches' => 0, 'counts' => 0, 'refusal' => null, 'bodies' => []];
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($flow, $facts, $provider, $stream, $guard, $output): Response {
            $body = (string) $request->getBody();
            $facts->bodies[] = $body;
            $kind = $flow[$facts->attempts++] ?? throw new \LogicException('Unexpected request.');
            $wire = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($output, $wire[$provider === 'anthropic' ? 'max_tokens' : 'max_completion_tokens']);
            if ($guard) {
                ++$facts->counts;
                if ($kind === 'exceeded' || $kind === 'unavailable') {
                    $facts->refusal = $kind === 'exceeded'
                        ? new InputBudgetExceededException(24577, 32768, 8192, hash('sha256', $body))
                        : new InputBudgetUnavailableException(hash('sha256', $body));
                    throw $facts->refusal;
                }
            }
            ++$facts->dispatches;
            if ($kind === 'http400') {
                return new Response(400, [], json_encode(['error' => ['type' => 'exceed_context_size_error', 'n_prompt_tokens' => 33000, 'n_ctx' => 32768]]));
            }
            $message = ['role' => 'assistant', 'content' => 'Inspection completed.'];
            $finish = 'stop';
            if ($kind === 'tool') {
                $message['content'] = '';
                $message['tool_calls'] = [['index' => 0, 'id' => 'inspect-1', 'type' => 'function', 'function' => ['name' => 'inspect', 'arguments' => '{}']]];
                $finish = 'tool_calls';
            } elseif ($kind === 'degenerate') {
                $message['content'] = '';
                $message['reasoning_content'] = str_repeat('synthetic ', 1000);
            }
            if ($provider === 'anthropic') {
                $content = $kind === 'tool' ? [['type' => 'tool_use', 'id' => 'inspect-1', 'name' => 'inspect', 'input' => (object)[]]] : [['type' => 'text', 'text' => $message['content']]];
                return new Response(200, [], json_encode(['stop_reason' => $kind === 'tool' ? 'tool_use' : 'end_turn', 'content' => $content]));
            }
            if ($stream) {
                return new Response(200, ['Content-Type' => 'text/event-stream'], 'data: ' . json_encode(['choices' => [['delta' => $message,'finish_reason' => $finish]]]) . "\n\ndata: [DONE]\n\n");
            }
            return new Response(200, [], json_encode(['choices' => [['message' => $message, 'finish_reason' => $finish]]]));
        });
        $tools = $this->createMock(McpClientService::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'inspect', 'description' => 'Inspect.', 'inputSchema' => ['type' => 'object']]]);
        $tools->expects(self::exactly(count(array_filter($flow, fn ($v) => $v === 'tool'))))->method('callTool')->with('inspect', [])->willReturn('Measured once.');
        $llm = new LlmService('', 'fixture', $provider, httpClient: $http, onStreamChunk: $stream ? static function (): void {
        } : null);
        return [new AgentOrchestrator($llm, $tools, maxSteps: 4, contextTokens: $context, outputTokens: $output), $facts];
    }
}
