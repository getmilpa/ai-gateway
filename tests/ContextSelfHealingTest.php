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
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\ContextExceededException;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The context self-healing falsifiers (greenhouse fixture series, run 12).
 *
 * The measured killer: the intra-leg bound (0.17.0) estimates ONLY the messages, while the tools
 * array (62 schemas, ~10k+ tokens) rides the same request outside the count — and chars/4
 * underestimates code-heavy payloads — so the provider answered the verbatim exceed-context 400
 * («request (33246 tokens) exceeds the available context size (32768)») ONE step from the first
 * full completion. These tests encode the law of the fix: the tools share is counted in the
 * per-call bound; the exceed-context 400 is the ONE 4xx with a governed response — shrink the
 * leg's working budget by the provider's own measured overage, re-project from full history,
 * retry, leg-sticky, bounded at two heals per call — and ONLY when a budget exists to shrink.
 * Every other 4xx keeps surfacing verbatim un-retried, and an unbudgeted caller keeps today's
 * verbatim error.
 */
final class ContextSelfHealingTest extends TestCase
{
    /** The provider's declared window (tokens) the fake enforces on every request it receives. */
    private const PROVIDER_CTX_TOKENS = 9000;

    /**
     * A fake provider that MEASURES each outgoing request the way the real one does — messages
     * plus tools, chars over four — and answers the verbatim exceed-context 400 (with
     * `n_prompt_tokens`/`n_ctx`) whenever the request exceeds its window; a fitting request gets
     * the next scripted assistant message. The script advances only on success, exactly like a
     * real provider: a healed retry receives the answer the failed call was owed.
     *
     * @param list<array<string, mixed>> $script   assistant messages to answer with, in order
     * @param list<RequestInterface>     $sends    out-param: every request received
     * @param list<int>                  $statuses out-param: every status answered
     */
    private function exceedingProviderClient(int $ctxTokens, array $script, ?array &$sends = null, ?array &$statuses = null): ClientInterface
    {
        $sends = [];
        $statuses = [];

        return new class ($ctxTokens, $script, $sends, $statuses) implements ClientInterface {
            private int $answered = 0;

            /** @param list<array<string, mixed>> $script */
            public function __construct(
                private int $ctxTokens,
                private array $script,
                private array &$sends,
                private array &$statuses,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sends[] = $request;
                $payload = json_decode((string) $request->getBody(), true);
                $chars = \strlen((string) json_encode($payload['messages'] ?? [], JSON_UNESCAPED_UNICODE))
                    + \strlen((string) json_encode($payload['tools'] ?? [], JSON_UNESCAPED_UNICODE));
                $nPrompt = intdiv($chars, 4);

                if ($nPrompt > $this->ctxTokens) {
                    $this->statuses[] = 400;

                    return new Response(400, [], (string) json_encode(['error' => [
                        'code' => 400,
                        'message' => "request ({$nPrompt} tokens) exceeds the available context size ({$this->ctxTokens})",
                        'type' => 'exceed_context_size_error',
                        'n_prompt_tokens' => $nPrompt,
                        'n_ctx' => $this->ctxTokens,
                    ]]));
                }

                $message = $this->script[$this->answered] ?? ['role' => 'assistant', 'content' => 'done'];
                ++$this->answered;
                $this->statuses[] = 200;

                return new Response(200, [], (string) json_encode(['choices' => [['message' => $message]]]));
            }
        };
    }

    /**
     * A scripted climbing leg against the fake provider: `$fatSteps` tool steps each returning a
     * distinct ~6000-char result, then a final answer — through a REAL {@see LlmService} so the
     * whole chain is exercised: HTTP 400 body → typed exception → orchestrator heal.
     *
     * @param list<RequestInterface> $sends    out-param: every request the provider received
     * @param list<int>              $statuses out-param: every status it answered
     */
    private function runClimbingLegAgainstProvider(int $contextTokens, int $fatSteps, ?array &$sends = null, ?array &$statuses = null): string
    {
        $script = [];
        for ($n = 1; $n <= $fatSteps; ++$n) {
            $script[] = [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    ['id' => "c{$n}", 'type' => 'function', 'function' => ['name' => 'fat_tool', 'arguments' => '{}']],
                ],
            ];
        }
        $script[] = ['role' => 'assistant', 'content' => 'the answer lands after the climb'];

        $client = $this->exceedingProviderClient(self::PROVIDER_CTX_TOKENS, $script, $sends, $statuses);
        $llm = new LlmService('key', 'qwen', 'openai', null, $client);

        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([
            ['name' => 'fat_tool', 'description' => 'returns a fat payload', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
        ]);
        $resultNo = 0;
        $mcp->method('callTool')->willReturnCallback(function () use (&$resultNo) {
            ++$resultNo;

            return "RESULT-{$resultNo}-" . str_repeat('x', 6000);
        });

        $orchestrator = new AgentOrchestrator($llm, $mcp, $fatSteps + 5, null, null, null, false, null, $contextTokens);

        return $orchestrator->run('climb');
    }

    /**
     * Falsifier 1: a climbing leg that would exceed the provider's real window HEALS — shrink,
     * re-project, retry — and the answer LANDS, with the heal noted additively on the healed
     * message (`context_healed: true`) and never more than MAX_CONTEXT_HEALS extra calls for
     * the step that healed.
     */
    public function testAClimbingLegThatWouldExceedHealsAndTheAnswerLands(): void
    {
        $result = $this->runClimbingLegAgainstProvider(16000, 10, $sends, $statuses);

        self::assertStringContainsString('the answer lands after the climb', $result, 'the leg must END WITH THE ANSWER, not the 400');

        $fails = array_keys($statuses, 400, true);
        self::assertNotEmpty($fails, 'the leg must actually hit the provider wall — otherwise nothing was falsified');
        self::assertLessThanOrEqual(
            2,
            \count($fails),
            'never more than MAX_CONTEXT_HEALS extra calls per step, and the learned budget must hold'
        );

        // The heal is noted additively on the healed message: the NEXT request's history carries
        // the assistant turn with context_healed riding it — the record stays honest.
        $healedNoted = false;
        foreach ($sends as $request) {
            $payload = json_decode((string) $request->getBody(), true);
            foreach ($payload['messages'] ?? [] as $message) {
                if (($message['context_healed'] ?? false) === true) {
                    $healedNoted = true;
                }
            }
        }
        self::assertTrue($healedNoted, 'the healed message must carry context_healed: true');
    }

    /**
     * Falsifier 2: LEG-STICKY — after one heal the learned budget applies to every later call of
     * the leg: the provider sees them under its window WITHOUT further heals. The provider
     * taught the real ratio once; it is not re-learned per call.
     */
    public function testTheLearnedBudgetIsLegStickyAndLaterCallsPassWithoutFurtherHeals(): void
    {
        $this->runClimbingLegAgainstProvider(16000, 10, $sends, $statuses);

        $firstFail = array_search(400, $statuses, true);
        self::assertIsInt($firstFail, 'the leg must hit the wall once for stickiness to be observable');

        // The 400s live only at the single heal site: after the healed retry (the first 200
        // following the first 400) every remaining call of the leg passes on its first try,
        // because the learned budget is already applied — not re-learned per call.
        $healedAt = null;
        foreach ($statuses as $n => $status) {
            if ($n > $firstFail && $status === 200) {
                $healedAt = $n;

                break;
            }
        }
        self::assertIsInt($healedAt, 'the heal must recover — falsifier 1 covers the landing');
        $tail = \array_slice($statuses, $healedAt + 1);
        self::assertNotContains(400, $tail, 'a later 400 would mean the budget was re-learned per call instead of leg-sticky');
        self::assertGreaterThan(2, \count($tail), 'the leg must continue several calls past the heal for stickiness to mean anything');
    }

    /**
     * Falsifier 3: HONEST SURRENDER — a protected set alone too big for the provider's window
     * cannot be healed by elision: after the bounded heals the verbatim error surfaces, and
     * never more than 1 + MAX_CONTEXT_HEALS sends are spent.
     */
    public function testAProtectedSetAloneTooBigSurrendersWithTheVerbatimErrorAfterBoundedHeals(): void
    {
        $client = $this->exceedingProviderClient(self::PROVIDER_CTX_TOKENS, [], $sends, $statuses);
        $llm = new LlmService('key', 'qwen', 'openai', null, $client);

        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([
            ['name' => 'fat_tool', 'description' => 'returns a fat payload', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]],
        ]);

        $orchestrator = new AgentOrchestrator($llm, $mcp, 5, null, null, null, false, null, 16000);

        try {
            // A system prompt of ~60k chars (~15k tokens) is protected — nothing is elidible.
            $orchestrator->run('climb', 'SYSTEM ' . str_repeat('s', 60000));
            self::fail('a protected set nothing can shrink must surrender with the provider error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exceeds the available context size', $e->getMessage(), 'the verbatim provider error surfaces');
        }

        self::assertCount(3, $sends, 'one original call plus exactly MAX_CONTEXT_HEALS healing retries, then surrender');
        self::assertSame([400, 400, 400], $statuses);
    }

    /**
     * Control: a 401 keeps surfacing verbatim on ONE send — auth is never healed, never retried.
     */
    public function testA401SurfacesVerbatimOnOneSendNeverHealed(): void
    {
        $sends = [];
        $client = new class ($sends) implements ClientInterface {
            /** @param list<RequestInterface> $sends */
            public function __construct(private array &$sends)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sends[] = $request;

                return new Response(401, [], '{"error":"bad key"}');
            }
        };
        $llm = new LlmService('key', 'qwen', 'openai', null, $client);
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([]);

        $orchestrator = new AgentOrchestrator($llm, $mcp, 5, null, null, null, false, null, 16000);

        try {
            $orchestrator->run('hola');
            self::fail('a 401 must surface');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringContainsString('bad key', $e->getMessage());
            self::assertNotInstanceOf(ContextExceededException::class, $e, 'a 401 is never classified as context-exceeded');
        }
        self::assertCount(1, $sends, 'one send: auth failures are never healed or retried');
    }

    /**
     * Control: a 400 WITHOUT the narrow exceed-context type keeps surfacing verbatim on one
     * send — the governed response is for exactly one body signature, nothing wider.
     */
    public function testANonExceed400SurfacesVerbatimOnOneSendNeverHealed(): void
    {
        $sends = [];
        $client = new class ($sends) implements ClientInterface {
            /** @param list<RequestInterface> $sends */
            public function __construct(private array &$sends)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sends[] = $request;

                return new Response(400, [], '{"error":{"code":400,"message":"malformed request","type":"invalid_request_error"}}');
            }
        };
        $llm = new LlmService('key', 'qwen', 'openai', null, $client);
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([]);

        $orchestrator = new AgentOrchestrator($llm, $mcp, 5, null, null, null, false, null, 16000);

        try {
            $orchestrator->run('hola');
            self::fail('a malformed-request 400 must surface');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('malformed request', $e->getMessage());
            self::assertNotInstanceOf(ContextExceededException::class, $e, 'only the narrow body signature is the typed exception');
        }
        self::assertCount(1, $sends, 'one send: a non-exceed 400 is never healed');
    }

    /**
     * Control: an UNBUDGETED caller (contextTokens = 0) keeps today's behavior exactly — the
     * exceed-context 400 surfaces verbatim on one send. Healing without a budget to shrink
     * would be guesswork, so there is none.
     */
    public function testWithoutADeclaredBudgetTheExceed400SurfacesVerbatimAsToday(): void
    {
        $client = $this->exceedingProviderClient(self::PROVIDER_CTX_TOKENS, [], $sends, $statuses);
        $llm = new LlmService('key', 'qwen', 'openai', null, $client);
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([]);

        $orchestrator = new AgentOrchestrator($llm, $mcp, 5, null, null, null, false, null, 0);

        try {
            $orchestrator->run('climb', 'SYSTEM ' . str_repeat('s', 60000));
            self::fail('the exceed-400 must surface when no budget exists');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('OpenAI API Error: HTTP 400', $e->getMessage(), 'the message contract is unchanged');
            self::assertStringContainsString('exceeds the available context size', $e->getMessage());
        }
        self::assertCount(1, $sends, 'one send: no heal without a budget to shrink');
    }

    /**
     * Falsifier 5: TOOLS COUNTED — with a huge tools array and messages that alone would fit,
     * the projection still elides message tool-results, because the bound sees messages PLUS
     * tools against the leg budget.
     */
    public function testAHugeToolsArrayTightensTheMessageShareOfTheBudget(): void
    {
        $llm = $this->createMock(LlmService::class);
        $mcp = $this->createMock(McpClientService::class);

        // One tool whose schema alone is ~20k chars (~5k tokens): the provider contract rides
        // every request and is never elided — the message share must tighten instead. Sized so
        // the protected working set (the newest four results) still fits the tightened share:
        // the falsifier proves counting, not an impossible budget.
        $fatSchema = [
            'type' => 'object',
            'properties' => ['payload' => ['type' => 'string', 'description' => str_repeat('d', 20000)]],
        ];
        $tools = [['name' => 'fat_tool', 'description' => 'returns a fat payload', 'inputSchema' => $fatSchema]];
        $mcp->method('getToolSummaries')->willReturn($tools);

        $resultNo = 0;
        $mcp->method('callTool')->willReturnCallback(function () use (&$resultNo) {
            ++$resultNo;

            return "RESULT-{$resultNo}-" . str_repeat('x', 6000);
        });

        $captured = [];
        $call = 0;
        $llm->method('generateResponse')
            ->willReturnCallback(function ($prompt, $tools, $messages) use (&$captured, &$call) {
                $captured[] = $messages;
                ++$call;
                if ($call <= 6) {
                    return [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            ['id' => "c{$call}", 'type' => 'function', 'function' => ['name' => 'fat_tool', 'arguments' => '{}']],
                        ],
                    ];
                }

                return ['role' => 'assistant', 'content' => 'done — the leg ends with a real answer'];
            });

        $contextTokens = 16000;
        $budget = (int) floor($contextTokens * 0.75);
        $toolsTokens = intdiv(mb_strlen((string) json_encode($tools, JSON_UNESCAPED_UNICODE)), 4);

        $orchestrator = new AgentOrchestrator($llm, $mcp, 12, null, null, null, false, null, $contextTokens);
        $orchestrator->run('climb');

        self::assertCount(7, $captured, 'six tool steps and the final call all went out');

        $sawElision = false;
        foreach ($captured as $n => $projection) {
            $messagesEstimate = $this->estimate($projection);
            self::assertLessThanOrEqual(
                $budget - $toolsTokens,
                $messagesEstimate,
                "call {$n}: the message share must fit inside budget MINUS the tools share — the bound must see both"
            );
            foreach ($projection as $message) {
                if (($message['role'] ?? '') === 'tool' && str_contains((string) $message['content'], 'elided')) {
                    $sawElision = true;
                }
            }
        }
        self::assertTrue(
            $sawElision,
            'messages alone were under the budget — only a bound that counts the tools share forces this elision'
        );
    }

    /**
     * The narrow parse: the verbatim provider body becomes the typed exception, numbers carried,
     * and the message contract ("{provider} API Error: HTTP 400 - ...") stays intact.
     */
    public function testTheExceedBodyBecomesTheTypedExceptionCarryingTheProviderNumbers(): void
    {
        $body = '{"error":{"code":400,"message":"request (33246 tokens) exceeds the available context size (32768)",'
            . '"type":"exceed_context_size_error","n_prompt_tokens":33246,"n_ctx":32768}}';
        $client = new class ($body) implements ClientInterface {
            public function __construct(private string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(400, [], $this->body);
            }
        };
        $llm = new LlmService('key', 'qwen', 'openai', null, $client);

        try {
            $llm->generateResponse('p', [], []);
            self::fail('the exceed-400 must throw');
        } catch (ContextExceededException $e) {
            self::assertSame(33246, $e->nPromptTokens, 'the provider\'s own measured prompt size is carried');
            self::assertSame(32768, $e->nCtx, 'the provider\'s own window is carried');
            self::assertStringContainsString('OpenAI API Error: HTTP 400', $e->getMessage(), 'the message contract is preserved');
        }
    }

    /**
     * The narrow parse without numbers: the type alone is enough to classify, and the numbers
     * stay null — «not said», never fabricated — so the heal falls back conservatively.
     */
    public function testTheExceedBodyWithoutNumbersCarriesNulls(): void
    {
        $body = '{"error":{"code":400,"message":"context exceeded","type":"exceed_context_size_error"}}';
        $client = new class ($body) implements ClientInterface {
            public function __construct(private string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(400, [], $this->body);
            }
        };
        $llm = new LlmService('key', 'qwen', 'openai', null, $client);

        try {
            $llm->generateResponse('p', [], []);
            self::fail('the exceed-400 must throw');
        } catch (ContextExceededException $e) {
            self::assertNull($e->nPromptTokens);
            self::assertNull($e->nCtx);
        }
    }

    /**
     * The estimator the projection bound uses, mirrored here so the assertions measure with the
     * same ruler: serialized message chars over four.
     *
     * @param list<array<string, mixed>> $projection
     */
    private function estimate(array $projection): int
    {
        $chars = 0;
        foreach ($projection as $message) {
            $encoded = json_encode($message, JSON_UNESCAPED_UNICODE);
            $chars += mb_strlen($encoded === false ? '' : $encoded);
        }

        return intdiv($chars, 4);
    }
}
