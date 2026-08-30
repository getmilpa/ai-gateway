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
use Milpa\AiGateway\ReasoningObserver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * What a model call REASONED, observed where the response is decoded.
 *
 * {@see ReturnObserverTest} pins the cost seam; this pins the reasoning seam beside it. The property
 * that separates it from a guess: the reasoning handed over must be the `reasoning_content` the
 * PROVIDER spoke, verbatim. A model that reasons silently produces no observation — an absent field
 * is «not said», never an empty reasoning fabricated to look like one.
 */
final class ReasoningObserverTest extends TestCase
{
    private function clientReturning(string $body): ClientInterface
    {
        return new class ($body) implements ClientInterface {
            public function __construct(private string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->body);
            }
        };
    }

    /** An observer that sees the request AND the reasoning, so one test proves the reasoning without losing the request. */
    private function reasoningSeam(array &$requests, array &$reasonings): ChannelObserver
    {
        return new class ($requests, $reasonings) implements ChannelObserver, ReasoningObserver {
            /**
             * @param list<string>                                $requests
             * @param list<array{uri: string, reasoning: string}> $reasonings
             */
            public function __construct(private array &$requests, private array &$reasonings)
            {
            }

            public function observe(string $uri, array $payload): void
            {
                $this->requests[] = $uri;
            }

            public function observeReasoning(string $uri, string $reasoning): void
            {
                $this->reasonings[] = ['uri' => $uri, 'reasoning' => $reasoning];
            }
        };
    }

    /** A request-only observer: it must NEVER be handed reasoning, even when the message carries it. */
    private function requestOnly(array &$requests): ChannelObserver
    {
        return new class ($requests) implements ChannelObserver {
            public function __construct(private array &$requests)
            {
            }

            public function observe(string $uri, array $payload): void
            {
                $this->requests[] = $uri;
            }
        };
    }

    public function testTheReasoningContentIsReportedVerbatim(): void
    {
        $requests = [];
        $reasonings = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'qwen3.8-27b',
            provider: 'openai',
            httpClient: $this->clientReturning((string) json_encode([
                'choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => '17 × 23 = 391',
                    'reasoning_content' => 'Break 23 into 20 + 3; 17×20=340, 17×3=51, 340+51=391.',
                ]]],
            ])),
            channelObserver: $this->reasoningSeam($requests, $reasonings),
        );

        $service->generateResponse('what is 17*23?');

        self::assertCount(1, $requests, 'the request seam still fires exactly once');
        self::assertCount(1, $reasonings, 'the reasoning seam fires exactly once, on success');
        self::assertSame($requests[0], $reasonings[0]['uri'], 'both seams name the same endpoint');
        self::assertSame(
            'Break 23 into 20 + 3; 17×20=340, 17×3=51, 340+51=391.',
            $reasonings[0]['reasoning'],
        );
    }

    public function testAMessageWithoutReasoningProducesNoObservation(): void
    {
        $requests = [];
        $reasonings = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'gpt-4o',
            provider: 'openai',
            httpClient: $this->clientReturning((string) json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ])),
            channelObserver: $this->reasoningSeam($requests, $reasonings),
        );

        $service->generateResponse('hola');

        self::assertCount(1, $requests, 'the request still travelled');
        self::assertCount(0, $reasonings, 'no reasoning was spoken, so none is fabricated');
    }

    public function testAnEmptyReasoningStringIsTreatedAsSilence(): void
    {
        $requests = [];
        $reasonings = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'qwen3.8-27b',
            provider: 'openai',
            httpClient: $this->clientReturning((string) json_encode([
                'choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => 'ok',
                    'reasoning_content' => '',
                ]]],
            ])),
            channelObserver: $this->reasoningSeam($requests, $reasonings),
        );

        $service->generateResponse('hola');

        self::assertCount(0, $reasonings, 'an empty reasoning field is «not said», not an empty observation');
    }

    public function testARequestOnlyObserverIsNeverHandedReasoning(): void
    {
        $requests = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'qwen3.8-27b',
            provider: 'openai',
            httpClient: $this->clientReturning((string) json_encode([
                'choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => 'ok',
                    'reasoning_content' => 'thinking…',
                ]]],
            ])),
            channelObserver: $this->requestOnly($requests),
        );

        // A ChannelObserver that did not opt into ReasoningObserver must not be called for reasoning.
        // If the gateway tried, this would fatal on an unknown method — the test's real assertion.
        $service->generateResponse('hola');

        self::assertCount(1, $requests);
    }
}
