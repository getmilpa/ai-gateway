<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{ChannelObserver,LlmService};
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/** Generation mode must be explicit, observable and confined to its supported protocol. */
final class MiniMaxThinkingTest extends TestCase
{
    public function testOmissionAndBothExplicitModesPreserveOtherWireFieldsAndOriginalClient(): void
    {
        foreach ([false, true] as $streaming) {
            $captured = [];
            $observer = new class () implements ChannelObserver {
                public array $payloads = [];
                public function observe(string $uri, array $payload): void
                {
                    $this->payloads[] = $payload;
                }
            };
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::exactly(3))->method('sendRequest')->willReturnCallback(function (RequestInterface $r) use (&$captured, $streaming): Response {
                $captured[] = json_decode((string) $r->getBody(), true);
                $body = $streaming
                    ? 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]) . "\n\ndata: [DONE]\n\n"
                    : json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']]]]);
                return new Response(200, [], $body);
            });
            $client = new LlmService('', 'MiniMax-M3', 'openai', httpClient: $http, channelObserver: $observer, onStreamChunk: $streaming ? static function (): void {
            } : null);
            $client->withMiniMaxThinking('disabled')->generateResponse('Read', maxTokens: 16384);
            $client->withMiniMaxThinking('adaptive')->generateResponse('Read', maxTokens: 16384);
            $client->generateResponse('Read', maxTokens: 16384);
            self::assertSame($captured, $observer->payloads);
            self::assertSame(['type' => 'disabled'], $captured[0]['thinking']);
            self::assertSame(['type' => 'adaptive'], $captured[1]['thinking']);
            unset($captured[0]['thinking'], $captured[1]['thinking']);
            self::assertSame($captured[2], $captured[0]);
            self::assertSame($captured[2], $captured[1]);
            self::assertArrayNotHasKey('reasoning_split', $captured[2]);
        }
    }

    public function testUnsupportedModelProviderOrModeFailsBeforeAnyRequest(): void
    {
        foreach ([['MiniMax-M2.7', 'openai', 'disabled'], ['fixture', 'openai', 'disabled'], ['MiniMax-M3', 'anthropic', 'disabled'], ['MiniMax-M3', 'openai', 'enabled'], ['MiniMax-M3', 'openai', '']] as [$model, $provider, $mode]) {
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::never())->method('sendRequest');
            try {
                (new LlmService('', $model, $provider, httpClient: $http))->withMiniMaxThinking($mode);
                self::fail('Unsupported profile accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('MiniMax thinking', $e->getMessage());
            }
        }
    }
}
