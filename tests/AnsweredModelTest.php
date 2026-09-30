<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\{ChannelObserver,LlmService,ReturnObserver};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

/**
 * The return fact names the model the provider SAYS answered, not only the one requested (greenhouse decisions/0542).
 *
 * Measured on Rod's endpoint: asked for `qwen3-coder:30b`, llama.cpp answers `"model":"qwen3.8-27b"` — buffered and
 * in every SSE chunk — and the house recorded the name it asked for in 48 of 48 returns (evidence/1071).
 *
 * @internal
 */
final class AnsweredModelTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function transports(): array
    {
        return ['OpenAI buffered' => ['openai', false], 'OpenAI SSE' => ['openai', true], 'Anthropic' => ['anthropic', false]];
    }

    #[DataProvider('transports')]
    public function testTheReturnNamesTheModelThatAnsweredAndTheOneRequested(string $provider, bool $stream): void
    {
        $meta = $this->returned($provider, $stream, 'qwen3.8-27b');

        self::assertSame('qwen3.8-27b', $meta['model']);
        self::assertSame('qwen3-coder:30b', $meta['requested_model']);
    }

    #[DataProvider('transports')]
    public function testTheSameModelIsSaidOnce(string $provider, bool $stream): void
    {
        $meta = $this->returned($provider, $stream, 'qwen3-coder:30b');

        self::assertSame('qwen3-coder:30b', $meta['model']);
        self::assertArrayNotHasKey('requested_model', $meta);
    }

    #[DataProvider('transports')]
    public function testAProviderThatSaysNothingLeavesTheRequestedName(string $provider, bool $stream): void
    {
        $meta = $this->returned($provider, $stream, null);

        self::assertSame('qwen3-coder:30b', $meta['model']);
        self::assertArrayNotHasKey('requested_model', $meta);
    }

    /** @return array<string, mixed> */
    private function returned(string $provider, bool $stream, ?string $answered): array
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturn($this->response($provider, $stream, $answered));
        $seen = null;
        $observer = $this->createMockForIntersectionOfInterfaces([ChannelObserver::class, ReturnObserver::class]);
        $observer->expects(self::once())->method('observeReturn')->willReturnCallback(static function (string $uri, array $meta) use (&$seen): void {
            $seen = $meta;
        });
        $gateway = new LlmService('', 'qwen3-coder:30b', $provider, httpClient: $client, channelObserver: $observer, onStreamChunk: $stream ? static function (string $piece): void {
        } : null);
        $gateway->generateResponse('Say hi.');
        self::assertIsArray($seen);

        return $seen;
    }

    private function response(string $provider, bool $stream, ?string $answered): Response
    {
        $model = $answered === null ? [] : ['model' => $answered];
        if ($provider === 'anthropic') {
            return new Response(200, [], json_encode($model + ['content' => [['type' => 'text', 'text' => 'Hi']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 3, 'output_tokens' => 1]]));
        }
        if (!$stream) {
            return new Response(200, [], json_encode($model + ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1]]));
        }

        return new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            'data: ' . json_encode($model + ['choices' => [['delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]]) . "\n\n"
            . 'data: ' . json_encode($model + ['choices' => [['delta' => [], 'finish_reason' => 'stop']]]) . "\n\n"
            . 'data: ' . json_encode($model + ['choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1]]) . "\n\ndata: [DONE]\n\n"
        );
    }
}
