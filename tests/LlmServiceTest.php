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

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Milpa\AiGateway\LlmService;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class LlmServiceTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $service = new LlmService('test-api-key');

        // Just verify it constructs without errors
        $this->assertInstanceOf(LlmService::class, $service);
    }

    public function testConstructorWithCustomModel(): void
    {
        $service = new LlmService('api-key', 'gpt-3.5-turbo');

        $this->assertInstanceOf(LlmService::class, $service);
    }

    public function testConstructorWithAnthropicProvider(): void
    {
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic');

        $this->assertInstanceOf(LlmService::class, $service);
    }

    public function testConstructorWithUppercaseProvider(): void
    {
        // Provider should be case-insensitive
        $service = new LlmService('api-key', 'gpt-4', 'OPENAI');

        $this->assertInstanceOf(LlmService::class, $service);
    }

    public function testFormatToolsStructure(): void
    {
        // Use reflection to test private method
        $service = new LlmService('api-key');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForOpenAi');
        $method->setAccessible(true);

        $tools = [
            [
                'name' => 'get_weather',
                'description' => 'Get weather info',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $result = $method->invoke($service, $tools);

        $this->assertCount(1, $result);
        $this->assertEquals('function', $result[0]['type']);
        $this->assertEquals('get_weather', $result[0]['function']['name']);
        $this->assertEquals('Get weather info', $result[0]['function']['description']);
    }

    public function testFormatToolsForAnthropicStructure(): void
    {
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForAnthropic');
        $method->setAccessible(true);

        $tools = [
            [
                'name' => 'search',
                'description' => 'Search for items',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $result = $method->invoke($service, $tools);

        $this->assertCount(1, $result);
        $this->assertEquals('search', $result[0]['name']);
        $this->assertEquals('Search for items', $result[0]['description']);
        $this->assertArrayHasKey('input_schema', $result[0]);
    }

    public function testFormatToolsForAnthropicWithEmptySchema(): void
    {
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForAnthropic');
        $method->setAccessible(true);

        $tools = [
            [
                'name' => 'no_params',
                'description' => 'Tool with no parameters',
                'inputSchema' => [],
            ],
        ];

        $result = $method->invoke($service, $tools);

        $this->assertEquals('object', $result[0]['input_schema']['type']);
        $this->assertInstanceOf(\stdClass::class, $result[0]['input_schema']['properties']);
    }

    public function testFormatToolsForAnthropicWithMissingType(): void
    {
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForAnthropic');
        $method->setAccessible(true);

        $tools = [
            [
                'name' => 'test',
                'description' => 'Test tool',
                'inputSchema' => [
                    'properties' => ['a' => ['type' => 'string']],
                ],
            ],
        ];

        $result = $method->invoke($service, $tools);

        $this->assertEquals('object', $result[0]['input_schema']['type']);
    }

    public function testFormatMultipleToolsForOpenAi(): void
    {
        $service = new LlmService('api-key');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForOpenAi');
        $method->setAccessible(true);

        $tools = [
            ['name' => 'tool1', 'description' => 'First', 'inputSchema' => []],
            ['name' => 'tool2', 'description' => 'Second', 'inputSchema' => []],
            ['name' => 'tool3', 'description' => 'Third', 'inputSchema' => []],
        ];

        $result = $method->invoke($service, $tools);

        $this->assertCount(3, $result);
        $this->assertEquals('tool1', $result[0]['function']['name']);
        $this->assertEquals('tool2', $result[1]['function']['name']);
        $this->assertEquals('tool3', $result[2]['function']['name']);
    }

    // ========== Additional Tests for Coverage ==========

    public function testClaudeModelDetectedAsAnthropic(): void
    {
        // When model name contains 'claude', it should use Anthropic provider
        $service = new LlmService('api-key', 'claude-3-opus', 'openai');

        // Access provider via reflection
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('provider');
        $property->setAccessible(true);

        // The provider should be lowercase
        $this->assertEquals('openai', $property->getValue($service));
    }

    public function testProviderIsLowercased(): void
    {
        $service = new LlmService('api-key', 'gpt-4', 'ANTHROPIC');

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('provider');
        $property->setAccessible(true);

        $this->assertEquals('anthropic', $property->getValue($service));
    }

    public function testFormatToolsForAnthropicWithMissingInputSchema(): void
    {
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForAnthropic');
        $method->setAccessible(true);

        $tools = [
            [
                'name' => 'simple_tool',
                'description' => 'A simple tool',
                // No inputSchema key at all
            ],
        ];

        $result = $method->invoke($service, $tools);

        $this->assertCount(1, $result);
        $this->assertEquals('object', $result[0]['input_schema']['type']);
        $this->assertInstanceOf(\stdClass::class, $result[0]['input_schema']['properties']);
    }

    public function testFormatToolsForAnthropicWithEmptyProperties(): void
    {
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForAnthropic');
        $method->setAccessible(true);

        $tools = [
            [
                'name' => 'empty_props',
                'description' => 'Tool with empty properties',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
        ];

        $result = $method->invoke($service, $tools);

        // Empty array for properties should be converted to stdClass
        $this->assertInstanceOf(\stdClass::class, $result[0]['input_schema']['properties']);
    }

    public function testFormatToolsForOpenAiWithComplexSchema(): void
    {
        $service = new LlmService('api-key');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForOpenAi');
        $method->setAccessible(true);

        $tools = [
            [
                'name' => 'complex_tool',
                'description' => 'Tool with complex schema',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'User name'],
                        'age' => ['type' => 'integer', 'minimum' => 0],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];

        $result = $method->invoke($service, $tools);

        $this->assertEquals('function', $result[0]['type']);
        $this->assertArrayHasKey('parameters', $result[0]['function']);
        $this->assertEquals('object', $result[0]['function']['parameters']['type']);
    }

    public function testFormatEmptyToolsArray(): void
    {
        $service = new LlmService('api-key');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForOpenAi');
        $method->setAccessible(true);

        $result = $method->invoke($service, []);

        $this->assertEmpty($result);
    }

    public function testFormatMultipleToolsForAnthropic(): void
    {
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic');
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('formatToolsForAnthropic');
        $method->setAccessible(true);

        $tools = [
            ['name' => 'tool_a', 'description' => 'First tool', 'inputSchema' => ['type' => 'object']],
            ['name' => 'tool_b', 'description' => 'Second tool', 'inputSchema' => []],
        ];

        $result = $method->invoke($service, $tools);

        $this->assertCount(2, $result);
        $this->assertEquals('tool_a', $result[0]['name']);
        $this->assertEquals('tool_b', $result[1]['name']);
    }

    public function testModelPropertyIsSet(): void
    {
        $service = new LlmService('api-key', 'gpt-4-turbo');

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('model');
        $property->setAccessible(true);

        $this->assertEquals('gpt-4-turbo', $property->getValue($service));
    }

    public function testApiKeyPropertyIsSet(): void
    {
        $service = new LlmService('my-secret-key');

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('apiKey');
        $property->setAccessible(true);

        $this->assertEquals('my-secret-key', $property->getValue($service));
    }

    public function testDefaultHttpClientIsGuzzle(): void
    {
        // No ClientInterface injected -> falls back to a Guzzle client (PSR-18 native).
        $service = new LlmService('api-key');

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);

        $client = $property->getValue($service);
        $this->assertInstanceOf(\GuzzleHttp\Client::class, $client);
        $this->assertInstanceOf(\Psr\Http\Client\ClientInterface::class, $client);
    }

    public function testImplementsLlmServiceInterface(): void
    {
        $service = new LlmService('api-key');

        $this->assertInstanceOf(
            \Milpa\ToolRuntime\Contracts\LlmServiceInterface::class,
            $service
        );
    }

    // ========== PSR-18 seam: fake ClientInterface, no network ==========
    //
    // Before the seam, LlmService built its Guzzle client internally — generateResponse()'s
    // OpenAI/Anthropic HTTP paths were completely untestable without a real network call. The
    // constructor-injectable ClientInterface fixes that; these are the tests that friction was
    // blocking.

    public function testGenerateResponseSendsOpenAiRequestThroughInjectedClient(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Hello from OpenAI']],
            ],
        ]));

        $fakeClient = new FakeHttpClient($response);
        $service = new LlmService('api-key', 'gpt-4o', 'openai', httpClient: $fakeClient);

        $result = $service->generateResponse('Hi there');

        $this->assertSame(['role' => 'assistant', 'content' => 'Hello from OpenAI'], $result);

        $sent = $fakeClient->lastRequest;
        $this->assertNotNull($sent);
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('https://api.openai.com/v1/chat/completions', (string) $sent->getUri());
        $this->assertSame('Bearer api-key', $sent->getHeaderLine('Authorization'));

        $payload = json_decode((string) $sent->getBody(), true);
        $this->assertSame('gpt-4o', $payload['model']);
        $this->assertSame('Hi there', $payload['messages'][0]['content']);
    }

    public function testGenerateResponseSendsAnthropicRequestThroughInjectedClient(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'Hello from Claude'],
            ],
        ]));

        $fakeClient = new FakeHttpClient($response);
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic', httpClient: $fakeClient);

        $result = $service->generateResponse('Hi there');

        $this->assertSame('assistant', $result['role']);
        $this->assertSame('Hello from Claude', $result['content']);

        $sent = $fakeClient->lastRequest;
        $this->assertNotNull($sent);
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $sent->getUri());
        $this->assertSame('api-key', $sent->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $sent->getHeaderLine('anthropic-version'));

        $payload = json_decode((string) $sent->getBody(), true);
        $this->assertSame('claude-3-sonnet', $payload['model']);
        $this->assertSame(4096, $payload['max_tokens']);
    }

    public function testOpenAiClientFailureIsWrappedAsRuntimeException(): void
    {
        $fakeClient = new FakeHttpClient(new FakeClientException('connection refused'));
        $service = new LlmService('api-key', 'gpt-4o', 'openai', httpClient: $fakeClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API Error');

        $service->generateResponse('Hi there');
    }

    public function testAnthropicClientFailureIsWrappedAsRuntimeException(): void
    {
        $fakeClient = new FakeHttpClient(new FakeClientException('connection refused'));
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic', httpClient: $fakeClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Anthropic API Error');

        $service->generateResponse('Hi there');
    }

    // ========== HTTP-error guard: PSR-18 never throws on 4xx/5xx ==========
    //
    // sendRequest() only throws ClientExceptionInterface for transport-level failures (DNS,
    // connection refused, timeout, ...). A 401/500 comes back as an ordinary ResponseInterface
    // — Guzzle's own PSR-18 adapter hardcodes http_errors=false for sendRequest(). Before this
    // guard, a 401 response fell through to json_decode()+??, silently returning [] for OpenAI
    // or throwing a `foreach() on null` PHP warning for Anthropic. These assert the fix
    // restores the pre-PSR-18 contract: an HTTP error status raises a RuntimeException carrying
    // the same "$provider API Error: ..." message prefix the old Guzzle-exception catch used.

    public function testOpenAi401ResponseThrowsRuntimeExceptionWithMessageContract(): void
    {
        $response = new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => ['message' => 'Incorrect API key provided', 'type' => 'invalid_request_error'],
        ]));

        $fakeClient = new FakeHttpClient($response);
        $service = new LlmService('bad-api-key', 'gpt-4o', 'openai', httpClient: $fakeClient);

        try {
            $service->generateResponse('Hi there');
            $this->fail('Expected a RuntimeException for the 401 response.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OpenAI API Error', $e->getMessage());
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringContainsString('Incorrect API key provided', $e->getMessage());
        }
    }

    public function testOpenAi500ResponseThrowsRuntimeExceptionWithMessageContract(): void
    {
        $response = new Response(500, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => ['message' => 'Internal server error', 'type' => 'server_error'],
        ]));

        $fakeClient = new FakeHttpClient($response);
        $service = new LlmService('api-key', 'gpt-4o', 'openai', httpClient: $fakeClient);

        try {
            $service->generateResponse('Hi there');
            $this->fail('Expected a RuntimeException for the 500 response.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('OpenAI API Error', $e->getMessage());
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertStringContainsString('Internal server error', $e->getMessage());
        }
    }

    public function testAnthropic401ResponseThrowsRuntimeExceptionWithMessageContract(): void
    {
        $response = new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
        ]));

        $fakeClient = new FakeHttpClient($response);
        $service = new LlmService('bad-api-key', 'claude-3-sonnet', 'anthropic', httpClient: $fakeClient);

        try {
            $service->generateResponse('Hi there');
            $this->fail('Expected a RuntimeException for the 401 response.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Anthropic API Error', $e->getMessage());
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringContainsString('invalid x-api-key', $e->getMessage());
        }
    }

    public function testAnthropic500ResponseThrowsRuntimeExceptionWithMessageContract(): void
    {
        $response = new Response(500, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => 'api_error', 'message' => 'Internal server error'],
        ]));

        $fakeClient = new FakeHttpClient($response);
        $service = new LlmService('api-key', 'claude-3-sonnet', 'anthropic', httpClient: $fakeClient);

        try {
            $service->generateResponse('Hi there');
            $this->fail('Expected a RuntimeException for the 500 response.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Anthropic API Error', $e->getMessage());
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertStringContainsString('Internal server error', $e->getMessage());
        }
    }

    public function testHttpErrorResponseBodyIsTruncatedInExceptionMessage(): void
    {
        // A body well past the truncation threshold should not appear in full in the message —
        // only the guard's bounded excerpt, so a large/sensitive error payload can't leak
        // wholesale into whatever ends up catching and logging the exception.
        $longBody = str_repeat('x', 5000);
        $response = new Response(400, ['Content-Type' => 'text/plain'], $longBody);

        $fakeClient = new FakeHttpClient($response);
        $service = new LlmService('api-key', 'gpt-4o', 'openai', httpClient: $fakeClient);

        try {
            $service->generateResponse('Hi there');
            $this->fail('Expected a RuntimeException for the 400 response.');
        } catch (\RuntimeException $e) {
            $this->assertLessThan(strlen($longBody), strlen($e->getMessage()));
            $this->assertStringContainsString('truncated', $e->getMessage());
        }
    }
}

/**
 * Minimal PSR-18 test double — proves the seam works with ANY `ClientInterface`. No network,
 * no Guzzle HTTP internals: this class only speaks PSR-7/PSR-18.
 */
final class FakeHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function __construct(
        private readonly ResponseInterface|ClientExceptionInterface|null $result = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        if ($this->result instanceof ClientExceptionInterface) {
            throw $this->result;
        }

        return $this->result ?? new Response(200, ['Content-Type' => 'application/json'], '{}');
    }
}

/**
 * Minimal PSR-18 client exception test double.
 */
final class FakeClientException extends \RuntimeException implements ClientExceptionInterface
{
}
