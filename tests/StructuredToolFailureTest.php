<?php

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use PHPUnit\Framework\TestCase;

/** Error-window controls from greenhouse 0380/0697.
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */
final class StructuredToolFailureTest extends TestCase
{
    /** Drive the actual loop, capture the next model request, and retain the pairing. */
    private function window(string $error, int $context = 32768): string
    {
        $llm = $this->createMock(LlmService::class);
        $mcp = $this->createMock(McpClientService::class);
        $mcp->method('getToolSummaries')->willReturn([['name' => 'operation', 'description' => 'fixture', 'inputSchema' => []]]);
        $mcp->expects(self::once())->method('callTool')->willThrowException(new \RuntimeException($error));
        $wire = null;
        $calls = 0;
        $llm->expects(self::exactly(2))->method('generateResponse')->willReturnCallback(
            function ($prompt, $tools, $messages) use (&$wire, &$calls): array {
                if (++$calls === 1) {
                    return ['role' => 'assistant', 'content' => '', 'tool_calls' => [[
                        'id' => 'failed-call', 'type' => 'function',
                        'function' => ['name' => 'operation', 'arguments' => '{}'],
                    ]]];
                }
                $message = array_values(array_filter($messages, static fn ($m) => ($m['role'] ?? '') === 'tool'))[0];
                self::assertSame('failed-call', $message['tool_call_id']);
                self::assertSame('operation', $message['name']);
                $wire = $message['content'];
                return ['role' => 'assistant', 'content' => 'done'];
            },
        );
        (new AgentOrchestrator($llm, $mcp, 3, contextTokens: $context))->run('fixture');
        self::assertIsString($wire);
        return $wire;
    }

    /** A large diagnostic cannot displace the native verdict, including null and zero. */
    public function testLongDiagnosticPreservesTheVerdictAcrossWindowSizes(): void
    {
        foreach ([0, 4, 4000, 8192, 32768] as $context) {
            foreach ([true, false] as $ran) {
                $original = ['ok' => false, 'workspace' => 'w-fixture', 'trial_exit' => 1,
                    'output' => ['ok' => false, 'ran' => $ran, 'tests' => $ran ? 12 : null,
                        'assertions' => $ran ? 274 : null, 'failures' => $ran ? 0 : null, 'errors' => $ran ? 1 : null],
                    'stderr' => str_repeat('diagnostic ', 4000)];
                $error = json_encode($original, JSON_THROW_ON_ERROR);
                $wire = $this->window($error, $context);
                $budget = in_array($context, [4000, 8192], true) ? (int) ($context * 0.75) : 8000;
                self::assertLessThanOrEqual($budget, mb_strlen($wire));
                $p = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame('milpa.tool-failure-window/v1', $p['schema']);
                self::assertFalse($p['ok']);
                self::assertTrue($p['partial']);
                $expected = $original;
                unset($expected['stderr']);
                self::assertSame($expected, $p['preview']);
                self::assertSame([['path' => '/stderr', 'characters' => mb_strlen($original['stderr']),
                    'sha256' => hash('sha256', $original['stderr'])]], $p['omitted']);
                self::assertSame(hash('sha256', $error), $p['original']['sha256']);
                self::assertSame(mb_strlen($error), $p['original']['characters']);
            }
        }
    }

    /** Independent long fields are omitted explicitly, with escaped JSON Pointer names. */
    public function testLongPhpunitOutputAndUnicodeKeepTheirOwnOmissionIdentities(): void
    {
        $out = str_repeat("falló\\\"\n", 5000);
        $stderr = str_repeat('🧪', 20000);
        $error = json_encode(['ok' => false, 'output' => ['ran' => true, 'failures' => 1, 'output' => $out],
            'a/b~c' => $stderr, 'empty' => new \stdClass()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $p = json_decode($this->window($error), flags: JSON_THROW_ON_ERROR);
        self::assertLessThanOrEqual(8000, mb_strlen(json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        self::assertSame(1, $p->preview->output->failures);
        self::assertInstanceOf(\stdClass::class, $p->preview->empty);
        $omitted = array_column(json_decode(json_encode($p->omitted), true), null, 'path');
        self::assertSame(hash('sha256', $out), $omitted['/output/output']['sha256']);
        self::assertSame(hash('sha256', $stderr), $omitted['/a~1b~0c']['sha256']);
    }

    /** An oversized structure is unavailable, never an invented empty or successful result. */
    public function testAnUnrepresentableStructureNamesTheWholeOmission(): void
    {
        foreach ([['values' => range(1, 10000)], array_fill_keys(array_map(strval(...), range(1, 10000)), false)] as $body) {
            $error = json_encode((object) $body, JSON_THROW_ON_ERROR);
            $p = json_decode($this->window($error), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($p['ok']);
            self::assertTrue($p['partial']);
            self::assertNull($p['preview']);
            self::assertSame('', $p['omitted'][0]['path']);
            self::assertSame(hash('sha256', $error), $p['omitted'][0]['sha256']);
        }
    }

    /** Short errors and non-JSON errors keep the existing error channel. */
    public function testUnchangedErrorsDoNotAcquireAProjection(): void
    {
        foreach (['permission refused', '{"ok":false,"output":null}', '[1,2]', '{broken'] as $error) {
            self::assertSame('Error executing tool: ' . $error, $this->window($error));
        }
        $error = str_repeat('plain failure ', 2000);
        $wire = $this->window($error);
        self::assertStringStartsWith('Error executing tool: plain failure', $wire);
        self::assertStringContainsString('tool result truncated:', $wire);
    }

    /** Encoding overhead alone does not justify losing a field that fits in the final wire. */
    public function testARepresentableErrorPreservesEveryValue(): void
    {
        $original = ['ok' => false, 'message' => str_repeat('ñ', 3000)];
        $error = json_encode($original, JSON_THROW_ON_ERROR);
        $p = json_decode($this->window($error), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($p['ok']);
        self::assertFalse($p['partial']);
        self::assertSame($original, $p['preview']);
        self::assertSame([], $p['omitted']);
        self::assertSame(hash('sha256', $error), $p['original']['sha256']);
    }

    /** The error transport cannot acquire success from a contradictory body. */
    public function testFailureTransportRemainsFailedEvenIfTheBodyClaimsSuccess(): void
    {
        $p = json_decode($this->window(json_encode(['ok' => true, 'diagnostic' => str_repeat('x', 20000)])), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($p['ok']);
        self::assertTrue($p['preview']['ok']);
        self::assertSame('/diagnostic', $p['omitted'][0]['path']);
    }

    /** Invalid or unsupported large JSON stays on the explicit legacy text path. */
    public function testUnsupportedStructuredErrorsRetainTheLegacyMarker(): void
    {
        foreach (['{' . str_repeat('broken', 2000), json_encode(range(1, 10000)),
            str_repeat('{"nested":', 65) . '"' . str_repeat('x', 9000) . '"' . str_repeat('}', 65)] as $error) {
            $wire = $this->window($error);
            self::assertStringStartsWith('Error executing tool: ', $wire);
            self::assertStringContainsString('tool result truncated:', $wire);
        }
    }

    /** Range/precision loss cannot become an altered fact in a valid-looking preview. */
    public function testNumbersThatCannotRoundTripLeaveThePreviewUnavailable(): void
    {
        foreach (['9223372036854775808123', '1.2345678901234567890123'] as $number) {
            $error = '{"count":' . $number . ',"stderr":"' . str_repeat('x', 12000) . '"}';
            $p = json_decode($this->window($error), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($p['ok']);
            self::assertNull($p['preview']);
            self::assertSame('', $p['omitted'][0]['path']);
            self::assertSame(hash('sha256', $error), $p['omitted'][0]['sha256']);
        }
    }

}
