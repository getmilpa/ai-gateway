<?php

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\LlmService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A provider 5xx whose body carries the malformed-model-tool-call signature — llama.cpp failing to
 * parse what its own model generated, measured verbatim on run 10 («Failed to parse tool call
 * arguments as JSON ... missing closing quote») — earns exactly ONE retry: new sampling usually
 * yields well-formed output. Any other 5xx stays final on the first answer, and a 4xx keeps
 * surfacing verbatim, never retried.
 */
final class ProviderFlakeRetryTest extends TestCase
{
    private const FLAKE_BODY = '{"error":{"code":500,"message":"Failed to parse tool call arguments as JSON: syntax error"}}';

    /** @param list<ResponseInterface> $queue */
    private function serviceWith(array $queue, ?array &$sends = null): LlmService
    {
        $sends = [];
        $client = new class ($queue, $sends) implements ClientInterface {
            /** @param list<ResponseInterface> $queue */
            public function __construct(private array $queue, private array &$sends)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sends[] = $request;

                return array_shift($this->queue) ?? new Response(200, [], '{}');
            }
        };

        return new LlmService('key', 'qwen', 'openai', null, $client);
    }

    public function testTheFlake500RetriesOnceAndTheRetrysAnswerStands(): void
    {
        $ok = new Response(200, [], (string) json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'hola']]],
        ]));
        $service = $this->serviceWith([new Response(500, [], self::FLAKE_BODY), $ok], $sends);

        $message = $service->generateResponse('p', [], []);

        self::assertCount(2, $sends, 'exactly one retry');
        self::assertSame('hola', $message['content']);
        self::assertTrue($message['provider_flake_retried'] ?? false, 'the record notes the retry');
    }

    public function testASecondFlakeIsFinalAtTwoSendsNeverThree(): void
    {
        $service = $this->serviceWith([
            new Response(500, [], self::FLAKE_BODY),
            new Response(500, [], self::FLAKE_BODY),
            new Response(200, [], '{}'),
        ], $sends);

        try {
            $service->generateResponse('p', [], []);
            self::fail('a second flake must be final');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('OpenAI API Error: HTTP 500', $e->getMessage());
            self::assertStringContainsString('Failed to parse tool call arguments', $e->getMessage());
        }
        self::assertCount(2, $sends, 'never a third attempt');
    }

    public function testAGenericFive00IsFinalOnTheFirstAnswer(): void
    {
        $service = $this->serviceWith([new Response(500, [], '{"error":"boom"}')], $sends);

        try {
            $service->generateResponse('p', [], []);
            self::fail('a generic 5xx must not retry');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('OpenAI API Error: HTTP 500', $e->getMessage());
        }
        self::assertCount(1, $sends, 'the narrow signature is the ONLY retryable 5xx');
    }

    public function testAFourHundredKeepsSurfacingVerbatimUnretried(): void
    {
        $service = $this->serviceWith([new Response(400, [], '{"error":"context exceeded"}')], $sends);

        try {
            $service->generateResponse('p', [], []);
            self::fail('a 4xx must surface');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('context exceeded', $e->getMessage());
        }
        self::assertCount(1, $sends);
    }
}
