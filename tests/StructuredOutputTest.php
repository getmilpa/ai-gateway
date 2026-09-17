<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use GuzzleHttp\Psr7\Response;
use Milpa\AiGateway\{ChannelObserver, LlmService, StructuredOutput};
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

final class StructuredOutputTest extends TestCase
{
    public function testAnExplicitCloneCarriesTheFormatAndPreservesTheDefaultAndTools(): void
    {
        foreach ([false, true] as $stream) {
            $seen = [];
            $wire = [];
            $observer = new class ($seen) implements ChannelObserver {
                public function __construct(private array &$seen)
                {
                }
                public function observe(string $uri, array $payload): void
                {
                    $this->seen[] = $payload;
                }
            };
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::exactly(3))->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use (&$wire, $stream): Response {
                $wire[] = json_decode((string)$request->getBody(), true);
                $message = ['role' => 'assistant','content' => '{"matches":false}'];
                return new Response(200, [], $stream
                    ? 'data: ' . json_encode(['choices' => [['delta' => $message,'finish_reason' => 'stop']]]) . "\n\ndata: [DONE]\n\n"
                    : json_encode(['choices' => [['message' => $message,'finish_reason' => 'stop']]]));
            });
            $gateway = new LlmService(
                'fixture',
                'fixture',
                'openai',
                httpClient:$http,
                channelObserver:$observer,
                onStreamChunk:$stream ? static function (string $piece): void {
                } : null
            );
            $output = new StructuredOutput('diagnosis', ['matches' => ['boolean']]);
            $tools = [['name' => 'read','description' => 'Read source','inputSchema' => ['type' => 'object']]];
            $before = $gateway->generateResponse('Diagnose', $tools);
            $candidate = $gateway->withStructuredOutput($output)->generateResponse('Diagnose', $tools);
            $after = $gateway->generateResponse('Diagnose', $tools);
            self::assertSame($before, $candidate);
            self::assertSame($before, $after);
            self::assertSame($wire, $seen);
            self::assertSame($wire[0], $wire[2]);
            self::assertArrayNotHasKey('response_format', $wire[0]);
            self::assertSame($output->toArray(), $wire[1]['response_format']);
            unset($wire[1]['response_format']);
            self::assertSame($wire[0], $wire[1]);
        }
    }

    public function testOnlyBoundedScalarObjectDeclarationsAreAdmitted(): void
    {
        foreach ([['', ['field' => ['string']]], ['name',[]], ['name',['field' => ['object']]],
            ['name',['field' => ['string','string']]], ['name',['bad key' => ['string']]],
            ['name',['field' => []]], ['name',['field' => 'string']], [str_repeat('n', 65),['f' => ['null']]],
            ['name',array_fill_keys(array_map(static fn ($i) => 'f' . $i, range(0, 64)), ['string'])]] as [$name,$fields]) {
            try {
                new StructuredOutput($name, $fields);
                self::fail('Invalid declaration accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $format = new StructuredOutput('diagnosis', ['value' => ['string','number','boolean','null']]);
        self::assertSame(['value'], $format->toArray()['json_schema']['schema']['required']);
        self::assertFalse($format->toArray()['json_schema']['schema']['additionalProperties']);
        self::assertTrue($format->toArray()['json_schema']['strict']);
    }

    public function testUnsupportedProviderRefusesBeforeHttpAndHttp400HasNoFallback(): void
    {
        $output = new StructuredOutput('diagnosis', ['matches' => ['boolean']]);
        foreach ([['anthropic','fixture'],['openai','claude-fixture']] as [$provider,$model]) {
            $http = $this->createMock(ClientInterface::class);
            $http->expects(self::never())->method('sendRequest');
            try {
                (new LlmService('fixture', $model, $provider, httpClient:$http))->withStructuredOutput($output);
                self::fail('Unsupported provider accepted');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('OpenAI-compatible', $e->getMessage());
            }
        }
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('sendRequest')->willReturn(new Response(400, [], 'unsupported response format'));
        $this->expectException(\RuntimeException::class);
        (new LlmService('fixture', 'fixture', httpClient:$http))->withStructuredOutput($output)->generateResponse('Diagnose');
    }
}
