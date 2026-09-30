<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\{AgentOrchestrator,LlmService,McpClientService,OutputTruncatedException,RunEnd};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * A reply that reaches the output limit gets ONE shortened retry before the leg ends (greenhouse decisions/0542).
 *
 * Rod's live run (evidence/1071, leg 2) spent 8,192 output tokens, ended `output_truncated` and never tried again,
 * with 26,562 tokens of its 49,152 window still free. Nothing from a truncated message ever executes (0334).
 *
 * @internal
 */
final class TruncationRetryTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function transports(): array
    {
        return ['OpenAI buffered' => ['openai', false], 'OpenAI SSE' => ['openai', true], 'Anthropic' => ['anthropic', false]];
    }

    #[DataProvider('transports')]
    public function testOneTruncationIsRetriedOnceShorterAndTheRecoveredCallsRun(string $provider, bool $stream): void
    {
        $bodies = [];
        $client = $this->client($provider, $stream, $bodies, ['truncated', 'tools', 'final']);
        $mcp = $this->mcp(self::exactly(2));

        $loop = new AgentOrchestrator($this->gateway($client, $provider, $stream), $mcp, maxSteps: 4, contextTokens: 49152, outputTokens: 8192);

        self::assertStringEndsWith('Complete.', $loop->run('Perform both writes.'));
        self::assertCount(3, $bodies, 'the truncated call, ONE retry, then the next step');
        self::assertSame(RunEnd::FinalAnswer, $loop->termination()?->reason);

        $retry = $bodies[1];
        $last = $this->lastUserLine($provider, $retry);
        self::assertStringContainsString('reached the output limit of 8192 tokens', $last);
        self::assertStringContainsString('Nothing from it was executed', $last);
        self::assertSame(16384, $this->limit($provider, $retry), 'the window has room: the retry gets twice the reserve');

        self::assertStringNotContainsString('reached the output limit', json_encode($bodies[2]), 'the nudge rides the retry only');
    }

    #[DataProvider('transports')]
    public function testTwoTruncationsInARowStopHonestlyAndRunNothing(string $provider, bool $stream): void
    {
        $bodies = [];
        $client = $this->client($provider, $stream, $bodies, ['truncated', 'truncated', 'final']);
        $loop = new AgentOrchestrator($this->gateway($client, $provider, $stream), $this->mcp(self::never()), maxSteps: 4, contextTokens: 49152, outputTokens: 8192);

        try {
            $loop->run('Perform both writes.');
            self::fail('A second truncation became an answer.');
        } catch (OutputTruncatedException $e) {
            self::assertTrue($e->retried);
            self::assertSame(16384, $e->maxTokens, 'the limit the RETRY asked for');
            self::assertStringContainsString('after one shortened retry', $e->getMessage());
        }
        self::assertCount(2, $bodies, 'exactly one retry, never a third call');
        self::assertSame(RunEnd::OutputTruncated, $loop->termination()?->reason);
    }

    public function testTheRetryIsPerCallNotPerLeg(): void
    {
        $bodies = [];
        $client = $this->client('openai', false, $bodies, ['truncated', 'tools', 'truncated', 'final']);
        $loop = new AgentOrchestrator($this->gateway($client, 'openai', false), $this->mcp(self::exactly(2)), maxSteps: 4, contextTokens: 49152, outputTokens: 8192);

        self::assertStringEndsWith('Complete.', $loop->run('Perform both writes.'));
        self::assertCount(4, $bodies);
    }

    public function testWithoutAKnownWindowTheRetryKeepsTheSameLimit(): void
    {
        $bodies = [];
        $client = $this->client('openai', false, $bodies, ['truncated', 'final']);
        $loop = new AgentOrchestrator($this->gateway($client, 'openai', false), $this->mcp(self::never()));

        self::assertStringEndsWith('Complete.', $loop->run('Answer.'));
        self::assertSame([4096, 4096], array_map(fn (array $b): int => $this->limit('openai', $b), $bodies));
    }

    public function testTheRetryNeverAsksForMoreThanTheWindowLeaves(): void
    {
        $bodies = [];
        $client = $this->client('openai', false, $bodies, ['truncated', 'final'], promptTokens: 9000);
        // 16,384 window, 4,096 reserve; the provider counted the request at ~9,000 tokens: 2× the
        // reserve (8,192) does not fit, the room left (minus the safety margin) does — and never
        // less than the reserve itself.
        $loop = new AgentOrchestrator($this->gateway($client, 'openai', false), $this->mcp(self::never()), contextTokens: 16384, outputTokens: 4096);

        self::assertStringEndsWith('Complete.', $loop->run('Answer.', str_repeat('protected context ', 1500)));
        $limit = $this->limit('openai', $bodies[1]);
        self::assertGreaterThanOrEqual(4096, $limit);
        self::assertLessThan(8192, $limit);
        self::assertLessThanOrEqual(16384 - 9000, $limit);
    }

    /**
     * @param list<array<string, mixed>>        $bodies
     * @param list<'truncated'|'tools'|'final'> $script
     */
    private function client(string $provider, bool $stream, array &$bodies, array $script, int $promptTokens = 3): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$bodies, $script, $provider, $stream, $promptTokens): Response {
            $bodies[] = json_decode((string) $request->getBody(), true);
            $kind = $script[\count($bodies) - 1] ?? 'final';

            return $this->response($provider, $stream, $kind, $promptTokens);
        });

        return $client;
    }

    private function mcp(mixed $calls): McpClientService
    {
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([['name' => 'write', 'description' => 'Write', 'inputSchema' => []]]);
        $mcp->expects($calls)->method('callTool')->willReturn(['ok' => true]);

        return $mcp;
    }

    private function gateway(ClientInterface $client, string $provider, bool $stream): LlmService
    {
        return new LlmService('', 'fixture-model', $provider, httpClient: $client, onStreamChunk: $stream ? static function (string $piece): void {
        } : null);
    }

    /** @param array<string, mixed> $body */
    private function limit(string $provider, array $body): int
    {
        return (int) $body[$provider === 'openai' ? 'max_completion_tokens' : 'max_tokens'];
    }

    /** @param array<string, mixed> $body */
    private function lastUserLine(string $provider, array $body): string
    {
        $messages = $body['messages'];
        $last = end($messages);
        self::assertSame('user', $last['role'], 'a user-role line: qwen rejects a system message that is not first');

        return \is_string($last['content']) ? $last['content'] : json_encode($last['content']);
    }

    /** @param 'truncated'|'tools'|'final' $kind */
    private function response(string $provider, bool $stream, string $kind, int $promptTokens): Response
    {
        $tools = $kind !== 'final';
        $truncated = $kind === 'truncated';
        $calls = array_map(static fn (int $id): array => ['id' => 'call-' . $id, 'type' => 'function', 'function' => ['name' => 'write', 'arguments' => '{}']], [1, 2]);
        $message = ['role' => 'assistant', 'content' => $tools ? '' : 'Complete.'];
        if ($tools) {
            $message['tool_calls'] = $calls;
        }
        $reason = $truncated ? 'length' : ($tools ? 'tool_calls' : 'stop');
        if ($provider === 'anthropic') {
            $content = $tools ? array_map(static fn (array $call): array => ['type' => 'tool_use', 'id' => $call['id'], 'name' => 'write', 'input' => new \stdClass()], $calls) : [['type' => 'text', 'text' => 'Complete.']];

            return new Response(200, [], json_encode(['content' => $content, 'stop_reason' => $truncated ? 'max_tokens' : ($tools ? 'tool_use' : 'end_turn'), 'usage' => ['input_tokens' => $promptTokens, 'output_tokens' => 7]]));
        }
        $usage = ['prompt_tokens' => $promptTokens, 'completion_tokens' => 7];
        if (!$stream) {
            return new Response(200, [], json_encode(['choices' => [['message' => $message, 'finish_reason' => $reason]], 'usage' => $usage]));
        }
        foreach ($message['tool_calls'] ?? [] as $i => $call) {
            $message['tool_calls'][$i]['index'] = $i;
        }

        return new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            'data: ' . json_encode(['choices' => [['delta' => $message, 'finish_reason' => null]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['finish_reason' => $reason]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => $usage]) . "\n\ndata: [DONE]\n\n"
        );
    }
}
