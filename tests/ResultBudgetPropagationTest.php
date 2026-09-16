<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\ToolRuntime\Contracts\ContextualToolHandler;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The native call shares the exact JSON ruler used for the following model message. */
final class ResultBudgetPropagationTest extends TestCase
{
    /** A host's global mbstring setting must not change the JSON transport's UTF-8 ruler. */
    public function testHostEncodingDoesNotChangeTheTransportRuler(): void
    {
        $previous = mb_internal_encoding();
        mb_internal_encoding('ISO-8859-1');
        try {
            $this->testProducerReceivesTheActualBudgetAndLegacyCallsDoNot(8192, 6144);
        } finally {
            mb_internal_encoding($previous);
        }
    }

    public static function windows(): iterable
    {
        yield 'large' => [32768, 8000];
        yield 'small' => [8192, 6144];
        yield 'undeclared' => [0, 8000];
        yield 'existing floor' => [4, 8000];
    }

    #[DataProvider('windows')]
    public function testProducerReceivesTheActualBudgetAndLegacyCallsDoNot(int $window, int $expected): void
    {
        $handler = new class () implements ContextualToolHandler {
            public array $contexts = [];
            public array $page = [];
            public function __invoke(array $arguments, ToolContext $context): mixed
            {
                $this->contexts[] = $context;
                $budget = $context->resultBudget;
                if ($budget === null) {
                    return 'legacy';
                }
                $page = ['content' => "ñ🌽/\n"];
                $padding = $budget->maxCharacters - mb_strlen($budget->encode($page), 'UTF-8');
                $page['content'] .= str_repeat('x', $padding);
                return $this->page = $page;
            }
        };
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('page', 'Budgeted page', ['type' => 'object'], $handler);
        $client = new McpClientService($registry);
        $client->setContext(new ToolContext('reader', 'mcp', ['read'], 'same-request'));
        $llm = $this->createMock(LlmService::class);
        $requests = [];
        $llm->method('generateResponse')->willReturnCallback(static function ($prompt, $tools, $messages) use (&$requests): array {
            $requests[] = $messages;
            if (count($requests) === 1) {
                return ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                    ['id' => 'page-1', 'type' => 'function', 'function' => ['name' => 'page', 'arguments' => '{}']],
                ]];
            }
            return ['role' => 'assistant', 'content' => 'The complete page was received.'];
        });
        (new AgentOrchestrator($llm, $client, maxSteps: 3, contextTokens: $window))->run('Read one page.');
        self::assertCount(2, $requests);
        self::assertCount(1, $handler->contexts);
        $budget = $handler->contexts[0]->resultBudget;
        self::assertSame($expected, $budget->maxCharacters);
        $message = array_values(array_filter($requests[1], static fn (array $m): bool => $m['role'] === 'tool'))[0];
        self::assertSame($budget->encode($handler->page), $message['content']);
        self::assertSame($expected, mb_strlen($message['content'], 'UTF-8'));
        self::assertSame($handler->page, json_decode($message['content'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('legacy', $client->callTool('page', []));
        self::assertNull($handler->contexts[1]->resultBudget);
        self::assertSame($handler->contexts[0]->toArray(), $handler->contexts[1]->toArray());
    }
}
