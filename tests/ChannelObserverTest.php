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
use Milpa\AiGateway\ChannelObserver;
use Milpa\AiGateway\LlmService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * What actually travelled to the model, observed where it is serialized.
 *
 * A developer surface may not know more than the channel it derives from. These tests pin the one
 * property that separates an observation from a re-implementation of it: the payload handed to the
 * observer must be BYTE-IDENTICAL to the body of the request that went out. Anything reconstructed
 * alongside the send would drift, and a view that drifts is the defect this seam exists to prevent.
 */
final class ChannelObserverTest extends TestCase
{
    /** A client that answers plausibly and keeps every request it was given. */
    private function clientRecording(array &$sent, string $body): ClientInterface
    {
        return new class ($sent, $body) implements ClientInterface {
            /** @param list<RequestInterface> $sent */
            public function __construct(private array &$sent, private string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], $this->body);
            }
        };
    }

    /** An observer that records what it was told, and nothing else. */
    private function observerRecording(array &$seen): ChannelObserver
    {
        return new class ($seen) implements ChannelObserver {
            /** @param list<array{uri: string, payload: array<string, mixed>}> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function observe(string $uri, array $payload): void
            {
                $this->seen[] = ['uri' => $uri, 'payload' => $payload];
            }
        };
    }

    public function testTheObservedPayloadIsTheBodyThatWasSent(): void
    {
        $sent = [];
        $seen = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'gpt-4o',
            provider: 'openai',
            httpClient: $this->clientRecording($sent, (string) json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ])),
            channelObserver: $this->observerRecording($seen),
        );

        $service->generateResponse('hola', [
            ['name' => 'plugins_list', 'description' => 'lists plugins', 'inputSchema' => ['type' => 'object']],
        ]);

        self::assertCount(1, $seen, 'one send, one observation');
        self::assertCount(1, $sent);

        // Compared as BYTES, not as decoded arrays. Decoding with assoc=true flattens `{}` into an
        // empty array, so an array/object difference between the observation and the wire would slip
        // through — and this seam exists precisely to catch differences of that size.
        self::assertSame(
            (string) $sent[0]->getBody(),
            (string) json_encode($seen[0]['payload']),
            'the observation must BE what travelled, not a parallel construction of it',
        );
    }

    public function testTheObservationCarriesTheToolsAsTheProviderSawThem(): void
    {
        $sent = [];
        $seen = [];

        $service = new LlmService(
            apiKey: 'k',
            provider: 'openai',
            httpClient: $this->clientRecording($sent, (string) json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ])),
            channelObserver: $this->observerRecording($seen),
        );

        $service->generateResponse('hola', [
            ['name' => 'plugins_list', 'description' => 'a', 'inputSchema' => ['type' => 'object']],
            ['name' => 'config_set', 'description' => 'b', 'inputSchema' => ['type' => 'object']],
        ]);

        $names = array_map(
            static fn (array $t): string => $t['function']['name'],
            $seen[0]['payload']['tools'],
        );

        self::assertSame(['plugins_list', 'config_set'], $names);
    }

    /**
     * The seam has to hold on BOTH providers or it is not the channel's throat — it is one branch of
     * it, and the other would go unobserved while the surface claimed to show everything.
     */
    public function testAnthropicIsObservedToo(): void
    {
        $sent = [];
        $seen = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'claude-sonnet-5',
            provider: 'anthropic',
            httpClient: $this->clientRecording($sent, (string) json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])),
            channelObserver: $this->observerRecording($seen),
        );

        $service->generateResponse('hola', [
            ['name' => 'plugins_list', 'description' => 'a', 'inputSchema' => ['type' => 'object']],
        ]);

        self::assertCount(1, $seen);

        self::assertSame((string) $sent[0]->getBody(), (string) json_encode($seen[0]['payload']));
        self::assertSame('claude-sonnet-5', $seen[0]['payload']['model']);
    }

    /**
     * Anthropic rewrites the conversation before sending it — it lifts the system prompt out of the
     * messages and converts `tool` roles. Observing at the CALLER would show messages that never
     * travelled in that shape. This is the test that fails if the seam ever moves upstream.
     */
    public function testTheObservationShowsTheREWRITTENConversationAndNotTheOneHandedIn(): void
    {
        $sent = [];
        $seen = [];

        $service = new LlmService(
            apiKey: 'k',
            provider: 'anthropic',
            httpClient: $this->clientRecording($sent, (string) json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])),
            channelObserver: $this->observerRecording($seen),
        );

        $service->generateResponse('', [], [
            ['role' => 'system', 'content' => 'you are a milpa agent'],
            ['role' => 'user', 'content' => 'hola'],
        ]);

        $roles = array_map(
            static fn (array $m): string => $m['role'],
            $seen[0]['payload']['messages'],
        );

        self::assertSame(['user'], $roles, 'the system message left the conversation');
        self::assertSame('you are a milpa agent', trim($seen[0]['payload']['system']));
    }

    public function testWithoutAnObserverNothingChanges(): void
    {
        $sent = [];

        $service = new LlmService(
            apiKey: 'k',
            httpClient: $this->clientRecording($sent, (string) json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ])),
        );

        $answer = $service->generateResponse('hola');

        self::assertSame('ok', $answer['content']);
        self::assertCount(1, $sent);
    }
}
