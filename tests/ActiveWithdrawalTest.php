<?php

/** The model client enforces active table withdrawal, not historical bookkeeping (greenhouse0362/0679).
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\{McpClientService, OptionTable, ToolCallRefusedException};
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ActiveWithdrawalTest extends TestCase
{
    public function testActiveTableIsReReadAndHistoricalOnlyTableStillExecutes(): void
    {
        $handled = 0;
        $r = new ToolRegistry(new NullLogger());
        $r->register('mark', 'Mark', ['type' => 'object'], static function () use (&$handled): string {
            ++$handled;
            return 'marked';
        });
        $table = new class () implements OptionTable {
            public array $active = [];
            public function remove(string $option, string $code, ?string $message = null): void
            {
                $this->active[] = $option;
            }
            public function removed(): array
            {
                return $this->active;
            }
            public function wasRemoved(string $option): bool
            {
                return true;
            }
        };
        $client = new McpClientService($r, table:$table);
        self::assertSame('marked', $client->callTool('mark', []));
        $table->remove('mark', 'denied-by-operator');
        self::assertSame([], $client->getToolSummaries());
        try {
            $client->callTool('mark', []);
            self::fail('Active withdrawal executed');
        } catch (ToolCallRefusedException $e) {
            self::assertTrue($e->optionRemoved);
            self::assertStringContainsString('withdrawn', $e->getMessage());
        }
        self::assertSame(1, $handled);
        $table->active = [];
        self::assertSame('marked', $client->callTool('mark', []));
        self::assertSame(2, $handled);
    }
    public function testNoTablePreservesExecution(): void
    {
        $r = new ToolRegistry(new NullLogger());
        $r->register('mark', 'Mark', ['type' => 'object'], static fn (): string => 'marked');
        self::assertSame('marked', (new McpClientService($r))->callTool('mark', []));
    }
}
