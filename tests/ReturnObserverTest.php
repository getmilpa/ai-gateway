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
use Milpa\AiGateway\ReturnObserver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * What a model call COST, observed where the response is decoded.
 *
 * {@see ChannelObserverTest} pins the request half; this pins the return half. The property that
 * separates it from a guess: the numbers reported to the observer must be the numbers the PROVIDER
 * spoke, collapsed to one shape but never invented. A provider that says nothing produces no
 * observation — silence is «not said», not a counted zero.
 */
final class ReturnObserverTest extends TestCase
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

    /** An observer that sees BOTH seams, so one test can prove the return is reported and the request still is. */
    private function bothSeams(array &$requests, array &$returns): ChannelObserver
    {
        return new class ($requests, $returns) implements ChannelObserver, ReturnObserver {
            /**
             * @param list<array{uri: string, payload: array<string, mixed>}> $requests
             * @param list<array{uri: string, meta: array<string, mixed>}>    $returns
             */
            public function __construct(private array &$requests, private array &$returns)
            {
            }

            public function observe(string $uri, array $payload): void
            {
                $this->requests[] = ['uri' => $uri, 'payload' => $payload];
            }

            public function observeReturn(string $uri, array $meta): void
            {
                $this->returns[] = ['uri' => $uri, 'meta' => $meta];
            }
        };
    }

    /** A request-only observer: it must NEVER be handed a return, even when the response carries usage. */
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

    public function testTheOpenAiUsageIsReportedNormalized(): void
    {
        $requests = [];
        $returns = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'qwen3.8-27b',
            provider: 'openai',
            httpClient: $this->clientReturning((string) json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
                'usage' => [
                    'prompt_tokens' => 17,
                    'completion_tokens' => 16,
                    'total_tokens' => 33,
                    'prompt_tokens_details' => ['cached_tokens' => 4],
                ],
            ])),
            channelObserver: $this->bothSeams($requests, $returns),
        );

        $service->generateResponse('hola');

        self::assertCount(1, $requests, 'the request seam still fires exactly once');
        self::assertCount(1, $returns, 'the return seam fires exactly once, on success');
        self::assertSame($requests[0]['uri'], $returns[0]['uri'], 'both seams name the same endpoint');
        self::assertSame('qwen3.8-27b', $returns[0]['meta']['model']);
        self::assertSame(
            ['prompt_tokens' => 17, 'completion_tokens' => 16, 'total_tokens' => 33, 'cached_tokens' => 4],
            $returns[0]['meta']['usage'],
        );
    }

    public function testTheAnthropicUsageIsCollapsedToTheSameShape(): void
    {
        $returns = [];
        $requests = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'claude-sonnet-4',
            provider: 'anthropic',
            httpClient: $this->clientReturning((string) json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 25, 'cache_read_input_tokens' => 10],
            ])),
            channelObserver: $this->bothSeams($requests, $returns),
        );

        $service->generateResponse('hola');

        self::assertCount(1, $returns);
        self::assertSame(
            // total is SUMMED because Anthropic reports none, and input/output become prompt/completion.
            ['prompt_tokens' => 100, 'completion_tokens' => 25, 'total_tokens' => 125, 'cached_tokens' => 10],
            $returns[0]['meta']['usage'],
        );
    }

    public function testAProviderThatReportsNoUsageProducesNoReturn(): void
    {
        $requests = [];
        $returns = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'gpt-4o',
            provider: 'openai',
            httpClient: $this->clientReturning((string) json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
            ])),
            channelObserver: $this->bothSeams($requests, $returns),
        );

        $service->generateResponse('hola');

        self::assertCount(1, $requests, 'the request still travelled');
        self::assertCount(0, $returns, 'no usage was spoken, so no return is fabricated');
    }

    public function testARequestOnlyObserverIsNeverHandedAReturn(): void
    {
        $requests = [];

        $service = new LlmService(
            apiKey: 'k',
            model: 'gpt-4o',
            provider: 'openai',
            httpClient: $this->clientReturning((string) json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ])),
            channelObserver: $this->requestOnly($requests),
        );

        // A ChannelObserver that did not opt into ReturnObserver must not be called for the return.
        // If the gateway tried, this would fatal on an unknown method — the test's real assertion.
        $service->generateResponse('hola');

        self::assertCount(1, $requests);
    }
}
