<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\AiGateway\OutputTruncatedException;
use Milpa\AiGateway\ChannelObserver;
use Milpa\AiGateway\ReturnObserver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/** A provider's incomplete batch never becomes tool execution. @internal */
final class OutputContractTest extends TestCase
{
    public static function transports(): array
    {
        return ['OpenAI buffered' => ['openai', false], 'OpenAI SSE' => ['openai', true], 'Anthropic' => ['anthropic', false]];
    }

    #[DataProvider('transports')]
    public function testExplicitLimitReachesEveryTransport(string $provider, bool $stream): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($provider, $stream): Response {
            $body = json_decode((string) $request->getBody(), true);
            self::assertSame(37, $body[$provider === 'openai' ? 'max_completion_tokens' : 'max_tokens'] ?? null);
            return $this->response($provider, $stream, false, false);
        });
        self::assertSame('Complete.', $this->gateway($client, $provider, $stream)->generateResponse('Answer.', maxTokens: 37)['content']);
    }

    #[DataProvider('transports')]
    public function testTruncatedBatchStopsBeforeAnyToolAndCompleteBatchExecutes(string $provider, bool $stream): void
    {
        foreach ([true, false] as $truncated) {
            $requests = 0;
            $client = $this->createMock(ClientInterface::class);
            $client->method('sendRequest')->willReturnCallback(function () use (&$requests, $provider, $stream, $truncated): Response {
                return $this->response($provider, $stream, ++$requests === 1, $truncated && $requests === 1);
            });
            $mcp = $this->createMock(McpClientService::class);
            $mcp->method('getToolSummaries')->willReturn([['name' => 'write', 'description' => 'Write', 'inputSchema' => []]]);
            $mcp->expects($truncated ? self::never() : self::exactly(2))->method('callTool')->willReturn(['ok' => true]);
            $loop = new AgentOrchestrator($this->gateway($client, $provider, $stream), $mcp, maxSteps: 3);
            $failure = null;
            try {
                self::assertStringEndsWith('Complete.', $loop->run('Perform both writes.'));
            } catch (OutputTruncatedException $error) {
                $failure = $error;
            }
            self::assertSame($truncated, $failure !== null);
            self::assertSame($truncated ? 1 : 2, $requests, 'truncation is not retried');
            if ($failure !== null) {
                self::assertSame(4096, $failure->maxTokens);
                self::assertSame($provider, $failure->provider);
            }
        }
    }

    public function testInvalidLimitDoesNotReachTheNetwork(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('sendRequest');
        $this->expectException(\InvalidArgumentException::class);
        $this->gateway($client, 'openai', false)->generateResponse('Answer.', maxTokens: 0);
    }

    #[DataProvider('transports')]
    public function testTruncationStillReportsUsage(string $provider, bool $stream): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($this->response($provider, $stream, true, true));
        $observer = $this->createMockForIntersectionOfInterfaces([ChannelObserver::class, ReturnObserver::class]);
        $observer->expects(self::once())->method('observeReturn')->with(self::anything(), self::callback(static fn (array $meta): bool => $meta['usage']['completion_tokens'] === 7));
        $gateway = new LlmService(
            '',
            'fixture',
            $provider,
            httpClient: $client,
            channelObserver: $observer,
            onStreamChunk: $stream ? static function (string $piece): void {
            } : null
        );
        $this->expectException(OutputTruncatedException::class);
        $gateway->generateResponse('Answer.');
    }

    public function testAnthropicContextTruncationKeepsItsActualReason(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->willReturn(new Response(200, [], '{"stop_reason":"model_context_window_exceeded","content":[]}'));
        try {
            $this->gateway($client, 'anthropic', false)->generateResponse('Answer.');
            self::fail('Known incomplete response returned.');
        } catch (OutputTruncatedException $error) {
            self::assertSame('model_context_window_exceeded', $error->stopReason);
        }
    }

    public function testDegenerateRetryCannotHideTruncation(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $calls = 0;
        $client->method('sendRequest')->willReturnCallback(function () use (&$calls): Response {
            if (++$calls === 1) {
                return new Response(200, [], json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '', 'reasoning_content' => str_repeat('synthetic ', 2000)]]]]));
            }
            return $this->response('openai', false, false, true);
        });
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([]);
        $mcp->expects(self::never())->method('callTool');
        $this->expectException(OutputTruncatedException::class);
        (new AgentOrchestrator($this->gateway($client, 'openai', false), $mcp))->run('Answer.');
    }

    private function gateway(ClientInterface $client, string $provider, bool $stream): LlmService
    {
        return new LlmService('', 'fixture-model', $provider, httpClient: $client, onStreamChunk: $stream ? static function (string $piece): void {
        } : null);
    }

    private function response(string $provider, bool $stream, bool $tools, bool $truncated): Response
    {
        $calls = array_map(static fn (int $id): array => ['id' => 'call-' . $id, 'type' => 'function', 'function' => ['name' => 'write', 'arguments' => '{}']], [1, 2]);
        $message = ['role' => 'assistant', 'content' => $tools ? '' : 'Complete.'];
        if ($tools) {
            $message['tool_calls'] = $calls;
        }
        $reason = $truncated ? 'length' : ($tools ? 'tool_calls' : 'stop');
        if ($provider === 'anthropic') {
            $content = $tools ? array_map(static fn (array $call): array => ['type' => 'tool_use', 'id' => $call['id'], 'name' => 'write', 'input' => new \stdClass()], $calls) : [['type' => 'text', 'text' => 'Complete.']];
            return new Response(200, [], json_encode(['content' => $content, 'stop_reason' => $truncated ? 'max_tokens' : ($tools ? 'tool_use' : 'end_turn'), 'usage' => ['input_tokens' => 3, 'output_tokens' => 7]]));
        }
        if (!$stream) {
            return new Response(200, [], json_encode(['choices' => [['message' => $message, 'finish_reason' => $reason]], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 7]]));
        }
        foreach ($message['tool_calls'] ?? [] as $i => $call) {
            $message['tool_calls'][$i]['index'] = $i;
        }
        return new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            'data: ' . json_encode(['choices' => [['delta' => $message, 'finish_reason' => null]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['finish_reason' => $reason]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 7]]) . "\n\ndata: [DONE]\n\n"
        );
    }
}
