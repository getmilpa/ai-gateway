<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\LlmService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

final class OllamaCloudTest extends TestCase
{
    public function testBothDocumentedBaseUrlsUseTheCloudWireAndPreserveNativeToolCalls(): void
    {
        foreach (['https://ollama.com', 'https://ollama.com/v1'] as $baseUrl) {
            $requests = [];
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::once())->method('sendRequest')->willReturnCallback(
                static function (RequestInterface $request) use (&$requests): Response {
                    $requests[] = $request;
                    return new Response(200, [], (string) json_encode(['choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => ['role' => 'assistant', 'content' => 'Promote the staged part.',
                            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => [
                                'name' => 'sandbox_promote', 'arguments' => '{"workspace":"w123"}',
                            ]]]],
                    ]]]));
                }
            );
            $service = new LlmService('test-key', 'glm-5.3-flash', 'openai', httpClient: $http, baseUrl: $baseUrl);
            $messages = [
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => 'call_0', 'type' => 'function', 'function' => ['name' => 'implement', 'arguments' => '{}'],
                ]], 'audio_content' => null],
                ['role' => 'tool', 'tool_call_id' => 'call_0', 'name' => 'implement', 'content' => '{"applied":false}'],
            ];
            $tools = [['name' => 'sandbox_promote', 'description' => 'Apply a trial',
                'inputSchema' => ['type' => 'object', 'properties' => ['workspace' => ['type' => 'string']]]]];

            $answer = $service->generateResponse('unused', $tools, $messages, 16384);

            self::assertSame('sandbox_promote', $answer['tool_calls'][0]['function']['name']);
            self::assertCount(1, $requests);
            self::assertSame('https://ollama.com/v1/chat/completions', (string) $requests[0]->getUri());
            self::assertSame('Bearer test-key', $requests[0]->getHeaderLine('Authorization'));
            $wire = json_decode((string) $requests[0]->getBody(), true);
            self::assertSame('glm-5.3-flash', $wire['model']);
            self::assertSame(16384, $wire['max_tokens']);
            self::assertArrayNotHasKey('max_completion_tokens', $wire);
            self::assertArrayNotHasKey('tool_choice', $wire);
            self::assertArrayNotHasKey('audio_content', $wire['messages'][0]);
            self::assertArrayNotHasKey('name', $wire['messages'][1]);
            self::assertSame('call_0', $wire['messages'][1]['tool_call_id']);
            self::assertSame('sandbox_promote', $wire['tools'][0]['function']['name']);
        }
    }

    public function testMiniMaxThinkingIsRejectedForTheOllamaCloudProfile(): void
    {
        $service = new LlmService('test-key', 'MiniMax-M3', 'openai', baseUrl: 'https://ollama.com/v1');
        $this->expectException(\InvalidArgumentException::class);
        $service->withMiniMaxThinking('adaptive');
    }

    public function testExplicitReasoningEffortIsSentToCompatibleCloudAndLocalEndpoints(): void
    {
        $request = null;
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $sent) use (&$request): Response {
                $request = $sent;
                return new Response(200, [], (string) json_encode(['choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Complete.'],
                ]]]));
            }
        );
        $service = new LlmService(
            'test-key',
            'glm-5.3-flash',
            'openai',
            httpClient: $http,
            baseUrl: 'https://ollama.com/v1'
        );

        $service->withOllamaReasoningEffort('max')->generateResponse('Build it.', maxTokens: 16384);

        self::assertInstanceOf(RequestInterface::class, $request);
        $wire = json_decode((string) $request->getBody(), true);
        self::assertSame('max', $wire['reasoning_effort']);
        self::assertSame(16384, $wire['max_tokens']);

        foreach (['', 'none', 'disabled', 'maximum'] as $effort) {
            try {
                $service->withOllamaReasoningEffort($effort);
                self::fail('Invalid reasoning effort accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Reasoning effort', $error->getMessage());
            }
        }

        $localRequest = null;
        $localHttp = $this->createMock(ClientInterface::class);
        $localHttp->expects(self::once())->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $sent) use (&$localRequest): Response {
                $localRequest = $sent;
                return new Response(200, [], (string) json_encode(['choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Complete.'],
                ]]]));
            }
        );
        (new LlmService(
            'unused',
            'qwen3.8-27b',
            'openai',
            httpClient: $localHttp,
            baseUrl: 'http://llama.local:11438/v1'
        ))
            ->withOllamaReasoningEffort('low')
            ->generateResponse('Build it.', [[
                'name' => 'probe_ready',
                'description' => 'Report readiness.',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ]], maxTokens: 6144);

        self::assertInstanceOf(RequestInterface::class, $localRequest);
        $localWire = json_decode((string) $localRequest->getBody(), true);
        self::assertSame('low', $localWire['reasoning_effort']);
        self::assertSame(6144, $localWire['max_completion_tokens']);
        self::assertSame('auto', $localWire['tool_choice']);

        $this->expectException(\InvalidArgumentException::class);
        (new LlmService('test-key', 'claude-sonnet-4-6', 'anthropic'))
            ->withOllamaReasoningEffort('low');
    }

    public function testExplicitChatTemplateThinkingIsSentOnlyWhenRequested(): void
    {
        foreach ([false, true] as $enabled) {
            $request = null;
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::once())->method('sendRequest')->willReturnCallback(
                static function (RequestInterface $sent) use (&$request): Response {
                    $request = $sent;
                    return new Response(200, [], (string) json_encode(['choices' => [[
                        'finish_reason' => 'stop',
                        'message' => ['role' => 'assistant', 'content' => 'Complete.'],
                    ]]]));
                }
            );
            (new LlmService(
                'unused',
                'qwen3.8-27b',
                'openai',
                httpClient: $http,
                baseUrl: 'http://llama.local:11438/v1'
            ))
                ->withOpenAiThinking($enabled)
                ->generateResponse('Build it.', maxTokens: 8192);

            self::assertInstanceOf(RequestInterface::class, $request);
            $wire = json_decode((string) $request->getBody(), true);
            self::assertSame(['enable_thinking' => $enabled], $wire['chat_template_kwargs']);
        }

        $this->expectException(\InvalidArgumentException::class);
        (new LlmService('unused', 'claude-sonnet-4-6', 'anthropic'))->withOpenAiThinking(false);
    }
}
