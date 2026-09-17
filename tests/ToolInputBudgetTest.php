<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\{AgentOrchestrator, LlmService, McpClientService};
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/** The output reserve measures schemas sent to the selected provider, not registry-only metadata. @internal */
final class ToolInputBudgetTest extends TestCase
{
    public function testUnsentMetadataCannotPreventAnOtherwiseIdenticalRequest(): void
    {
        foreach ([['openai', 'fixture'], ['anthropic', 'fixture'], ['openai', 'claude-fixture']] as [$provider, $model]) {
            $captured = [];
            foreach ([false, true] as $withMetadata) {
                $tool = $this->tool();
                if ($withMetadata) {
                    $tool['outputSchema'] = ['description' => str_repeat('registry only ', 12000)];
                    $tool['version'] = str_repeat('not transmitted ', 12000);
                }
                $http = $this->createMock(ClientInterface::class);
                $http->expects(self::once())->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$captured): Response {
                    $captured[] = (string) $request->getBody();
                    $input = json_decode(end($captured), true);
                    self::assertSame(8192, $input['max_completion_tokens'] ?? $input['max_tokens']);
                    return $this->answer(isset($input['max_tokens']));
                });
                $loop = new AgentOrchestrator(new LlmService('', $model, $provider, httpClient: $http), $this->tools($tool), contextTokens: 32768, outputTokens: 8192);
                self::assertStringEndsWith('The complete answer.', $loop->run('Read the contract.'));
            }
            self::assertSame($captured[0], $captured[1]);
        }
    }

    public function testAnOversizedSentInputSchemaStillRefusesBeforeTransport(): void
    {
        foreach (['openai', 'anthropic'] as $provider) {
            $tool = $this->tool();
            $tool['inputSchema']['description'] = str_repeat('required input ', 12000);
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::never())->method('sendRequest');
            $loop = new AgentOrchestrator(new LlmService('', 'fixture', $provider, httpClient: $http), $this->tools($tool), contextTokens: 32768, outputTokens: 8192);
            try {
                $loop->run('Read the contract.');
                self::fail('A schema actually sent must count against the input reserve.');
            } catch (\LengthException $error) {
                self::assertStringContainsString('declared output budget', $error->getMessage());
            }
        }
    }

    public function testProviderProjectionIsTheExactSentToolArray(): void
    {
        foreach ([['openai', 'fixture'], ['anthropic', 'fixture'], ['openai', 'claude-fixture']] as [$provider, $model]) {
            $tool = $this->tool();
            $tool['inputSchema'] = [];
            $tool['outputSchema'] = ['type' => 'string'];
            $tool['version'] = 'registry-only';
            $original = $tool;
            $sent = null;
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::once())->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$sent): Response {
                $input = json_decode((string) $request->getBody());
                $sent = $input->tools;
                return $this->answer(isset($input->max_tokens));
            });
            $llm = new LlmService('', $model, $provider, httpClient: $http);
            $projection = $llm->toolsForRequest([$tool]);
            $llm->generateResponse('Read.', [$tool]);
            self::assertSame(json_encode($projection), json_encode($sent));
            self::assertSame($original, $tool);
            self::assertSame([], $llm->toolsForRequest([]));
        }
    }

    public function testProjectionDoesNotDiscardReasoningOrToolCallHistory(): void
    {
        $assistant = ['role' => 'assistant', 'content' => '', 'reasoning_content' => 'Retained reasoning for the next tool step.',
            'tool_calls' => [['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{}']]]];
        $calls = 0;
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$calls, $assistant): Response {
            $input = json_decode((string) $request->getBody(), true);
            self::assertSame(8192, $input['max_completion_tokens']);
            if (++$calls === 1) {
                return new Response(200, [], json_encode(['choices' => [['finish_reason' => 'tool_calls', 'message' => $assistant]]]));
            }
            self::assertSame($assistant, $input['messages'][2]);
            self::assertSame(['role' => 'tool', 'tool_call_id' => 'call-1', 'name' => 'read', 'content' => 'Full tool result.'], $input['messages'][3]);
            return $this->answer(false);
        });
        $tools = $this->createMock(McpClientService::class);
        $tools->method('getToolSummaries')->willReturn([$this->tool()]);
        $tools->expects(self::once())->method('callTool')->with('read', [])->willReturn('Full tool result.');
        $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient: $http), $tools, contextTokens: 32768, outputTokens: 8192);
        self::assertStringEndsWith('The complete answer.', $loop->run('Read.'));
    }

    /** @return array<string, mixed> A lean input with optional registry metadata supplied by each case. */
    private function tool(): array
    {
        return ['name' => 'read', 'description' => 'Read the contract.', 'inputSchema' => ['type' => 'object']];
    }

    /** @param array<string, mixed> $tool The exact native summary returned by the registry. */
    private function tools(array $tool): McpClientService
    {
        $tools = $this->createMock(McpClientService::class);
        $tools->method('getToolSummaries')->willReturn([$tool]);
        $tools->expects(self::never())->method('callTool');
        return $tools;
    }

    /** Return one complete response in the selected provider's native format. */
    private function answer(bool $anthropic): Response
    {
        return new Response(200, [], json_encode(
            $anthropic
            ? ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'The complete answer.']]]
            : ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'The complete answer.']]]]
        ));
    }
}
