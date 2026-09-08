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

use Milpa\AiGateway\ProviderWindow;
use PHPUnit\Framework\TestCase;

/**
 * F1 of greenhouse decisions/0233: the house asks the provider its window, and only the ALLOCATED
 * one counts.
 *
 * The bodies below are the ones measured on 2026-09-08 against `qwen3.8-27b` on llama over
 * Tailscale — `n_ctx` 32768 next to `n_ctx_train` 262144, eight times apart. A reader that took the
 * training window would believe it had 262k and blow up at 32k, which is why the control here is a
 * provider that exposes ONLY the training window and must yield `null`.
 */
final class ProviderWindowTest extends TestCase
{
    /** The `/v1/models` body measured live, trimmed to the fields that matter. */
    private const MODELS_AS_MEASURED = '{"object":"list","data":[{"id":"qwen3.8-27b","object":"model","owned_by":"llamacpp","meta":{"vocab_type":2,"n_vocab":248320,"n_ctx":32768,"n_ctx_train":262144,"n_embd":5120}}]}';

    /** The `/props` body measured live, trimmed to the fields that matter. */
    private const PROPS_AS_MEASURED = '{"default_generation_settings":{"params":{"temperature":1.0},"n_ctx":32768},"total_slots":1,"model_alias":"qwen3.8-27b"}';

    public function testItAnswersTheWindowTheServerAllocatedAndNotTheOneTheModelWasTrainedFor(): void
    {
        $reader = new ProviderWindow('http://llama.tailf880b7.ts.net:11438', $this->serving([
            'http://llama.tailf880b7.ts.net:11438/v1/models' => self::MODELS_AS_MEASURED,
        ]));

        self::assertSame(32768, $reader->tokens(), 'n_ctx is the ceiling; n_ctx_train is 262144 and must not be it');
    }

    public function testAProviderThatExposesOnlyTheTrainingWindowSaysNothingAtAll(): void
    {
        $reader = new ProviderWindow('http://provider.test', $this->serving([
            'http://provider.test/v1/models' => '{"data":[{"id":"m","meta":{"n_ctx_train":262144}}]}',
            'http://provider.test/props' => '{"default_generation_settings":{"n_ctx_train":262144}}',
        ]));

        self::assertNull($reader->tokens(), 'a number that describes the model and not the server is not a ceiling');
    }

    public function testWhenTheOpenAiDoorSaysNothingItAsksLlamaCppsOwnDoor(): void
    {
        $reader = new ProviderWindow('http://provider.test', $this->serving([
            'http://provider.test/v1/models' => '{"object":"list","data":[]}',
            'http://provider.test/props' => self::PROPS_AS_MEASURED,
        ]));

        self::assertSame(32768, $reader->tokens(), '/props carries the same allocated figure');
    }

    public function testAnUnreachableProviderYieldsNullAndNeverRaises(): void
    {
        $reader = new ProviderWindow('http://nowhere.test', static fn (string $url): ?string => null);

        self::assertNull($reader->tokens());
    }

    public function testAProviderThatThrowsOnTheWireIsStillJustAProviderThatDidNotAnswer(): void
    {
        $reader = new ProviderWindow('http://nowhere.test', static function (string $url): ?string {
            throw new \RuntimeException('connection reset');
        });

        self::assertNull($reader->tokens(), 'nothing may escape to the caller — an unanswered question, never an exception');
    }

    public function testABodyThatIsNotJsonYieldsNull(): void
    {
        $reader = new ProviderWindow('http://provider.test', static fn (string $url): ?string => '<html>502 Bad Gateway</html>');

        self::assertNull($reader->tokens());
    }

    public function testJsonWithoutTheFieldYieldsNull(): void
    {
        $reader = new ProviderWindow('http://provider.test', $this->serving([
            'http://provider.test/v1/models' => '{"data":[{"id":"m","meta":{"n_embd":5120}}]}',
            'http://provider.test/props' => '{"total_slots":1}',
        ]));

        self::assertNull($reader->tokens());
    }

    /**
     * @return array<string, string>
     */
    public static function valuesThatAreNotACeiling(): array
    {
        return [
            'zero' => ['{"data":[{"meta":{"n_ctx":0}}]}'],
            'negative' => ['{"data":[{"meta":{"n_ctx":-1}}]}'],
            'a float' => ['{"data":[{"meta":{"n_ctx":32768.5}}]}'],
            'a numeric string' => ['{"data":[{"meta":{"n_ctx":"32768"}}]}'],
            'null' => ['{"data":[{"meta":{"n_ctx":null}}]}'],
            'an object' => ['{"data":[{"meta":{"n_ctx":{"value":32768}}}]}'],
        ];
    }

    /**
     * @dataProvider valuesThatAreNotACeiling
     */
    public function testAValueThatIsNotAPositiveWholeNumberIsNotAWindow(string $body): void
    {
        $reader = new ProviderWindow('http://provider.test', $this->serving([
            'http://provider.test/v1/models' => $body,
        ]));

        self::assertNull($reader->tokens(), 'a budget of 0 or -1 or a float would poison every derived share');
    }

    public function testItAsksOncePerInstanceBecauseAskingIsEgress(): void
    {
        $asked = [];
        $reader = new ProviderWindow('http://provider.test', function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return $url === 'http://provider.test/v1/models' ? self::MODELS_AS_MEASURED : null;
        });

        self::assertSame(32768, $reader->tokens());
        self::assertSame(32768, $reader->tokens());
        self::assertSame(32768, $reader->tokens());
        self::assertSame(['http://provider.test/v1/models'], $asked, 'one question per run, memoised');
    }

    public function testItMemoisesTheSilenceTooSoASilentProviderIsNotAskedOnEveryCall(): void
    {
        $asked = 0;
        $reader = new ProviderWindow('http://provider.test', function (string $url) use (&$asked): ?string {
            ++$asked;

            return null;
        });

        self::assertNull($reader->tokens());
        self::assertNull($reader->tokens());
        self::assertSame(2, $asked, 'both doors tried exactly once, and never again');
    }

    public function testAnEntryThatIsNotAnObjectIsSteppedOverRatherThanEndingTheSearch(): void
    {
        $reader = new ProviderWindow('http://provider.test', $this->serving([
            'http://provider.test/v1/models' => '{"data":["a string where an object was expected",{"meta":{"n_ctx":32768}}]}',
        ]));

        self::assertSame(32768, $reader->tokens(), 'one malformed entry must not hide the one that answers');
    }

    /**
     * The DEFAULT fetcher — the one production uses — read end to end, with no network.
     *
     * `file://` is a URL like any other to the fetcher, so the real closure runs against a real
     * body: a test that stubbed the seam here would prove the stub, not the shipped default. The
     * live counterpart of this test was run by hand against `qwen3.8-27b` and answered 32768
     * (greenhouse evidence for decisions/0233); this is the part of it that can live in CI.
     */
    public function testTheDefaultFetcherReadsABodyItWasNotHanded(): void
    {
        $root = sys_get_temp_dir() . '/milpa-provider-window-' . bin2hex(random_bytes(6));
        mkdir($root . '/v1', 0o775, true);
        file_put_contents($root . '/v1/models', self::MODELS_AS_MEASURED);

        try {
            $reader = new ProviderWindow('file://' . $root);
            self::assertSame(32768, $reader->tokens(), 'the shipped fetcher must read what it is pointed at');
        } finally {
            @unlink($root . '/v1/models');
            @rmdir($root . '/v1');
            @rmdir($root);
        }
    }

    public function testTheDefaultFetcherAnswersNullWhenThereIsNothingToRead(): void
    {
        $reader = new ProviderWindow('file://' . sys_get_temp_dir() . '/milpa-no-such-provider-' . bin2hex(random_bytes(6)));

        self::assertNull($reader->tokens(), 'the shipped fetcher must swallow its own failure, not raise it');
    }

    public function testBothSpellingsOfABaseUrlAskTheSamePlace(): void
    {
        foreach (['http://provider.test', 'http://provider.test/', 'http://provider.test/v1', 'http://provider.test/v1/'] as $spelling) {
            $reader = new ProviderWindow($spelling, $this->serving([
                'http://provider.test/v1/models' => self::MODELS_AS_MEASURED,
            ]));

            self::assertSame(32768, $reader->tokens(), "the base URL written as {$spelling} must reach the same door");
        }
    }

    /**
     * A fetcher that serves the given bodies by URL and `null` for anything else.
     *
     * @param array<string, string> $bodies
     *
     * @return callable(string): ?string
     */
    private function serving(array $bodies): callable
    {
        return static fn (string $url): ?string => $bodies[$url] ?? null;
    }
}
