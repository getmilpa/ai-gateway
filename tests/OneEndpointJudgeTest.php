<?php

/**
 * One endpoint, one normalization: the turn and the catalogue question read a base URL the same way.
 *
 * Measured on the published train (greenhouse evidence/1069 §C1): with `http://…:11438/v1` — the spelling every
 * OpenAI guide shows — `ProviderReach` asked `/v1/models` and answered «1 model(s) served», while the turn asked
 * `…/v1` + `/v1/chat/completions` and died on a 404 the panel never showed. Two judges of one endpoint.
 *
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\ProviderEndpoint;
use Milpa\AiGateway\ProviderReach;
use Milpa\AiGateway\ProviderRefusedException;
use Milpa\AiGateway\ProviderWindow;
use Milpa\AiGateway\RunEnd;
use Milpa\AiGateway\TransportRetryExhaustedException;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class OneEndpointJudgeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function spellings(): iterable
    {
        yield 'root' => ['http://provider.test:11438'];
        yield 'root, trailing slash' => ['http://provider.test:11438/'];
        yield 'the guide\'s /v1' => ['http://provider.test:11438/v1'];
        yield '/v1, trailing slash, padded' => ['  http://provider.test:11438/v1/  '];
    }

    #[DataProvider('spellings')]
    public function testTheTurnAsksWhereTheCatalogueQuestionAsked(string $spelling): void
    {
        $asked = [];
        $reach = new ProviderReach($spelling, fetch: static function (string $url) use (&$asked): string {
            $asked[] = $url;

            return '{"data":[{"id":"qwen3.8-27b"}]}';
        });
        self::assertSame(['qwen3.8-27b'], $reach->models());

        $sent = [];
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use (&$sent): Response {
            $sent[] = (string) $request->getUri();

            return new Response(200, [], '{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":"ok"}}]}');
        });
        (new LlmService('k', 'qwen3.8-27b', 'openai', httpClient: $http, baseUrl: $spelling))->generateResponse('hi');

        // The same root under both doors: what «served» was judged on is where the turn goes.
        self::assertSame(['http://provider.test:11438/v1/models'], $asked);
        self::assertSame(['http://provider.test:11438/v1/chat/completions'], $sent);
    }

    #[DataProvider('spellings')]
    public function testTheWindowReadsTheSameRoot(string $spelling): void
    {
        $asked = [];
        (new ProviderWindow($spelling, static function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return null;
        }))->tokens();

        self::assertSame('http://provider.test:11438/v1/models', $asked[0]);
    }

    public function testAnAnthropicBaseWithV1IsNotDoubledEither(): void
    {
        $sent = [];
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use (&$sent): Response {
            $sent[] = (string) $request->getUri();

            return new Response(200, [], '{"content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn"}');
        });
        (new LlmService('k', 'claude', 'anthropic', httpClient: $http, baseUrl: 'https://proxy.test/v1'))->generateResponse('hi');

        self::assertSame(['https://proxy.test/v1/messages'], $sent);
    }

    public function testTheOneNormalizationKeepsAPrefixPathAndOnlyDropsATrailingV1(): void
    {
        self::assertSame('https://openrouter.test/api', ProviderEndpoint::root('https://openrouter.test/api/v1'));
        self::assertSame('https://host.test/v1beta', ProviderEndpoint::root('https://host.test/v1beta'));
        self::assertSame('https://host.test/v1/proxy', ProviderEndpoint::root('https://host.test/v1/proxy/'));
        self::assertSame('https://host.test', ProviderEndpoint::root('https://host.test/V1'));
        self::assertSame('', ProviderEndpoint::root('   '));
    }

    public function testAnEndpointShownToAPersonCarriesNoCredentialAndNoQuery(): void
    {
        self::assertSame('http://llama.test:11438/v1/chat/completions', ProviderEndpoint::shown('http://user:secret@llama.test:11438/v1/chat/completions?key=abc#x'));
        self::assertSame('https://api.test/v1/messages', ProviderEndpoint::shown('https://api.test/v1/messages'));
        self::assertSame('not a url', ProviderEndpoint::shown('not a url'));
    }

    public function testARefusingEndpointEndsTheRunWithItsCauseNamed(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturn(new Response(404, [], '{"error":{"message":"File Not Found","type":"not_found_error","code":404}}'));
        $llm = new LlmService('k', 'qwen3.8-27b', 'openai', httpClient: $http, baseUrl: 'http://user:pw@provider.test:11438/v2');
        $loop = new AgentOrchestrator($llm, $this->noTools());

        try {
            $loop->run('Build the blog');
            self::fail('a 404 from the model endpoint must not read as an answer');
        } catch (\RuntimeException $e) {
            // The message callers already read is unchanged.
            self::assertStringStartsWith('OpenAI API Error: HTTP 404 Not Found - ', $e->getMessage());
            self::assertInstanceOf(ProviderRefusedException::class, $e);
        }

        self::assertSame(RunEnd::Failed, $loop->termination()?->reason);
        self::assertSame([
            'reason' => 'failed',
            'receipt' => null,
            'cause' => ['kind' => 'provider_refused', 'status' => 404, 'endpoint' => 'http://provider.test:11438/v2/v1/chat/completions'],
        ], $loop->termination()->toArray());
    }

    public function testAnUnreachableEndpointEndsTheRunWithItsCauseNamed(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willThrowException(new class ('connection refused', new Request('POST', 'http://x')) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        });
        $loop = new AgentOrchestrator(new LlmService('k', 'm', 'openai', httpClient: $http, baseUrl: 'http://down.test:1/v1'), $this->noTools());

        try {
            $loop->run('hi');
            self::fail('an unreachable endpoint must fail the run');
        } catch (TransportRetryExhaustedException) {
        }

        self::assertSame(['kind' => 'provider_unreachable', 'endpoint' => 'http://down.test:1/v1/chat/completions'], $loop->termination()?->toArray()['cause'] ?? null);
    }

    public function testAFailureThatIsNotTheEndpointsKeepsTheOldShape(): void
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willThrowException(new \LogicException('a bug of ours'));
        $loop = new AgentOrchestrator($llm, $this->noTools());

        try {
            $loop->run('hi');
        } catch (\LogicException) {
        }

        self::assertSame(['reason' => 'failed', 'receipt' => null], $loop->termination()?->toArray());
    }

    public function testACauseWrappedByTheStreamingPathIsStillFound(): void
    {
        $inner = new ProviderRefusedException('OpenAI API Error: HTTP 502', 502, 'http://p.test/v1/chat/completions');
        $llm = $this->createMock(LlmService::class);
        $llm->method('generateResponse')->willThrowException(new \RuntimeException('OpenAI API Error: ' . $inner->getMessage(), 0, $inner));
        $loop = new AgentOrchestrator($llm, $this->noTools());

        try {
            $loop->run('hi');
        } catch (\RuntimeException) {
        }

        self::assertSame(['kind' => 'provider_refused', 'status' => 502, 'endpoint' => 'http://p.test/v1/chat/completions'], $loop->termination()?->toArray()['cause'] ?? null);
    }

    private function noTools(): GatedToolCalls
    {
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([]);

        return $tools;
    }
}
