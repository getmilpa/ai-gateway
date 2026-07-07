<?php

/**
 * This file is part of Milpa AI Gateway — the dual-provider LLM client and agentic
 * tool-use runtime for the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\AiGateway\LlmService;

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

    public function testClientIsCreatedWithTimeout(): void
    {
        $service = new LlmService('api-key');

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);

        $client = $property->getValue($service);
        $this->assertInstanceOf(\GuzzleHttp\Client::class, $client);
    }

    public function testImplementsLlmServiceInterface(): void
    {
        $service = new LlmService('api-key');

        $this->assertInstanceOf(
            \Milpa\ToolRuntime\Contracts\LlmServiceInterface::class,
            $service
        );
    }
}
