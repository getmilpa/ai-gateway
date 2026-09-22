<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\{AgentOrchestrator,LlmService,RunEnd,ProgressProbe};
use Milpa\ToolRuntime\Gate\{GatedToolCalls,ToolCallRefused};
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

/** Local budget exhaustion may end a completed step; it never retries or completes work. @internal */
final class ContextBudgetTerminationTest extends TestCase
{
    private function firstResponse(): Response
    {
        return new Response(200, [], json_encode(['choices' => [['finish_reason' => 'tool_calls', 'message' => [
            'role' => 'assistant', 'content' => '', 'reasoning_content' => str_repeat('reason ', 3000),
            'tool_calls' => [['id' => 'write-once', 'type' => 'function', 'function' => ['name' => 'write', 'arguments' => '{"value":1}']]],
        ]]]]));
    }

    public function testCompletedToolStepYieldsBeforeAnotherRequestAndAnotherRunResetsTheCause(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::exactly(2))->method('sendRequest')->willReturnOnConsecutiveCalls(
            $this->firstResponse(),
            new Response(200, [], json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'A fresh invocation can answer.']]]])),
        );
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'write', 'description' => 'Write', 'inputSchema' => []]]);
        $tools->expects(self::once())->method('callTool')->with('write', ['value' => 1])->willReturn('Recorded effect');
        $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $tools, contextTokens:8192, outputTokens:4096);
        self::assertSame(AgentOrchestrator::CONTEXT_BUDGET_EXHAUSTED, $loop->run('Build.'));
        self::assertSame(RunEnd::ContextBudgetExhausted, $loop->termination()->reason);
        $receipt = $loop->termination()->receipt;
        self::assertSame(1, $receipt['completedSteps']);
        self::assertSame(8192, $receipt['contextTokens']);
        self::assertSame(4096, $receipt['outputTokens']);
        self::assertSame(4096, $receipt['inputLimitTokens']);
        self::assertGreaterThan(4096, $receipt['estimatedInputTokens']);
        self::assertSame('A fresh invocation can answer.', $loop->run('Continue.'));
        self::assertSame(RunEnd::FinalAnswer, $loop->termination()->reason);
        self::assertNull($loop->termination()->receipt);
    }

    public function testImpossibleInitialInputRemainsAFailureWithoutAnyRequest(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('sendRequest');
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([]);
        $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $tools, contextTokens:8192, outputTokens:4096);
        try {
            $loop->run(str_repeat('input ', 4000));
            self::fail('An impossible initial input must fail, not invite continuation.');
        } catch (\LengthException $error) {
            self::assertSame('The estimated input leaves no room for the declared output budget.', $error->getMessage());
        }
        self::assertSame(RunEnd::Failed, $loop->termination()->reason);
    }

    public function testPendingProgressReceiptSurvivesContextExhaustion(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('sendRequest')->willReturn($this->firstResponse());
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'write', 'description' => 'Write', 'inputSchema' => []]]);
        $tools->expects(self::once())->method('callTool')->willReturn('Recorded');
        $progress = ['window' => 4, 'reason' => 'no growth'];
        $probe = $this->createMock(ProgressProbe::class);
        $probe->method('afterStep')->willReturn(['stalled' => true, 'notice' => 'Choose a productive action.', 'receipt' => $progress, 'recovery' => 'pending']);
        $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $tools, progressProbe:$probe, contextTokens:8192, outputTokens:4096);
        $loop->run('Build.');
        self::assertSame(RunEnd::ContextBudgetExhausted, $loop->termination()->reason);
        self::assertSame($progress, $loop->termination()->receipt['progressReceipt']);
        self::assertSame('pending', $loop->termination()->receipt['recovery']);
    }

    public function testToolRefusalPrecedesContextExhaustion(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('sendRequest')->willReturn($this->firstResponse());
        $tools = $this->createMock(GatedToolCalls::class);
        $tools->method('getToolSummaries')->willReturn([['name' => 'write', 'description' => 'Write', 'inputSchema' => []]]);
        $tools->expects(self::once())->method('callTool')->willThrowException(new ToolCallRefused('Outside scope.'));
        $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $tools, contextTokens:8192, outputTokens:4096);
        self::assertSame('Outside scope.', $loop->run('Build.'));
        self::assertSame(RunEnd::ToolRefused, $loop->termination()->reason);
    }

    public function testWithoutBothDeclaredBudgetsTheExistingStepLimitRemains(): void
    {
        foreach ([[8192,null],[0,4096]] as [$context,$output]) {
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::exactly(2))->method('sendRequest')->willReturn($this->firstResponse());
            $tools = $this->createMock(GatedToolCalls::class);
            $tools->method('getToolSummaries')->willReturn([['name' => 'write', 'description' => 'Write', 'inputSchema' => []]]);
            $tools->expects(self::exactly(2))->method('callTool')->willReturn('Recorded');
            $loop = new AgentOrchestrator(new LlmService('', 'fixture', 'openai', httpClient:$http), $tools, maxSteps:2, contextTokens:$context, outputTokens:$output);
            self::assertSame(AgentOrchestrator::STEPS_EXHAUSTED, $loop->run('Build.'));
            self::assertSame(RunEnd::StepsExhausted, $loop->termination()->reason);
        }
    }
}
