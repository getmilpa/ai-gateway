<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\{AgentOrchestrator,LlmService,McpClientService,OutputTruncatedException};
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/** The native loop owns one finite output budget, including secondary generation paths. @internal */
final class OutputBudgetTest extends TestCase
{
    public function testDefaultAndExplicitLimitsReachBothProviders(): void
    {
        foreach (['openai','anthropic'] as $provider) {
            foreach ([null,37,8192] as $limit) {
                $http = $this->createMock(ClientInterface::class);
                $http->expects(self::once())->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($provider, $limit): Response {
                    $q = json_decode((string)$request->getBody(), true);
                    self::assertSame($limit ?? 4096, $q[$provider === 'openai' ? 'max_completion_tokens' : 'max_tokens']);
                    return $provider === 'openai' ? $this->answer() : new Response(200, [], json_encode(['stop_reason' => 'end_turn','content' => [['type' => 'text','text' => 'The complete answer.']]]));
                });
                $llm = new LlmService('', 'fixture', $provider, httpClient:$http);
                self::assertStringEndsWith('The complete answer.', (new AgentOrchestrator($llm, $this->tools(), contextTokens:32768, outputTokens:$limit))->run('Answer.'));
            }
        }
    }

    public function testInvalidLimitsRefuseBeforeNetwork(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('sendRequest');
        $llm = new LlmService('', 'fixture', 'openai', httpClient:$http);
        foreach ([[0,32768],[-1,32768],[32768,32768],[32769,32768]] as [$limit,$context]) {
            try {
                new AgentOrchestrator($llm, $this->tools(), contextTokens:$context, outputTokens:$limit);
                self::fail('Invalid output accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('outputTokens', $e->getMessage());
            }
        }
    }

    public function testExplicitReserveRefusesAnOversizedProtectedInput(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('sendRequest');
        $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $this->tools(), contextTokens:32768, outputTokens:32000);
        $this->expectException(\LengthException::class);
        $loop->run('Answer.', str_repeat('protected ', 1000));
    }

    public function testUnknownContextStillTransmitsAFiniteExplicitLimit(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('sendRequest')->willReturnCallback(function (RequestInterface $request): Response {
            self::assertSame(8192, json_decode((string)$request->getBody(), true)['max_completion_tokens']);
            return $this->answer();
        });
        self::assertStringEndsWith('The complete answer.', (new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $this->tools(), outputTokens:8192))->run('Answer.'));
    }

    public function testExistingRecoveryPathsKeepTheSameLimit(): void
    {
        foreach (['context','degeneration'] as $path) {
            $calls = 0;
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::exactly(2))->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$calls, $path): Response {
                self::assertSame(8192, json_decode((string)$request->getBody(), true)['max_completion_tokens']);
                if (++$calls === 1) {
                    if ($path === 'context') {
                        return new Response(400, [], json_encode(['error' => ['type' => 'exceed_context_size_error','n_prompt_tokens' => 33000,'n_ctx' => 32768,'message' => 'request exceeds the available context size']]));
                    }
                    return $this->answer('', str_repeat('synthetic ', 1000));
                }
                return $this->answer();
            });
            $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $this->tools(), contextTokens:32768, outputTokens:8192);
            self::assertStringEndsWith('The complete answer.', $loop->run('Answer.'));
        }
    }

    public function testExplicitOutputReservesSpaceByElidingOlderToolResults(): void
    {
        $last = [];
        foreach ([null,10000] as $limit) {
            $calls = 0;
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::exactly(9))->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$calls, &$last, $limit): Response {
                $q = json_decode((string)$request->getBody(), true);
                $last[$limit ?? 0] = $q;
                if (++$calls === 9) {
                    return $this->answer();
                }
                $message = ['role' => 'assistant','content' => '','tool_calls' => [
                    ['id' => 'call-' . $calls,'type' => 'function','function' => ['name' => 'read','arguments' => '{}']],
                ]];
                return new Response(200, [], json_encode(['choices' => [['finish_reason' => 'tool_calls','message' => $message]]]));
            });
            $tools = $this->createMock(McpClientService::class);
            $tools->method('getToolSummaries')->willReturn([['name' => 'read','description' => 'Read','inputSchema' => ['type' => 'object']]]);
            $tools->expects(self::exactly(8))->method('callTool')->willReturn(str_repeat('x', 4000));
            $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $tools, maxSteps:10, contextTokens:16384, outputTokens:$limit);
            self::assertStringEndsWith('The complete answer.', $loop->run('Read.'));
        }
        self::assertStringNotContainsString('[elided', json_encode($last[0]['messages']));
        self::assertStringContainsString('[elided', json_encode($last[10000]['messages']));
        self::assertSame(10000, $last[10000]['max_completion_tokens']);
    }

    public function testTruncationKeepsTheDeclaredLimitAndNeverExecutesTools(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $message = ['role' => 'assistant','content' => '','tool_calls' => [
            ['id' => 'partial','type' => 'function','function' => ['name' => 'edit','arguments' => '{"edits": [']],
        ]];
        $http->expects(self::once())->method('sendRequest')->willReturn(new Response(200, [], json_encode(['choices' => [['finish_reason' => 'length','message' => $message]]])));
        $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $this->tools(), contextTokens:32768, outputTokens:8192);
        try {
            $loop->run('Edit.');
            self::fail('Truncation accepted');
        } catch (OutputTruncatedException $e) {
            self::assertSame(8192, $e->maxTokens);
            self::assertSame('output_truncated', $loop->termination()->toArray()['reason']);
        }
    }

    private function tools(): McpClientService
    {
        $tools = $this->createMock(McpClientService::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $tools->expects(self::never())->method('callTool');
        return $tools;
    }

    private function answer(string $content = 'The complete answer.', string $reasoning = ''): Response
    {
        return new Response(200, [], json_encode(['choices' => [['finish_reason' => 'stop','message' => ['role' => 'assistant','content' => $content,'reasoning_content' => $reasoning]]]]));
    }
}
