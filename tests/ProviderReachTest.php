<?php

/**
 * This file is part of milpa/ai-gateway.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/ai-gateway
 */

declare(strict_types=1);

namespace Milpa\AiGateway\Tests;

use Milpa\AiGateway\ProviderReach;
use PHPUnit\Framework\TestCase;

/**
 * THE HOUSE ASKS WHETHER A MODEL ANSWERS, instead of reading what config claims.
 *
 * Nothing could say «there is no reachable model»: every surface read `agent.model` from config, and
 * the Desktop's footer printed one with a hardcoded fallback whose endpoint named `llama.local` — a
 * host that stopped resolving when that machine moved to Tailscale (greenhouse decisions/0266).
 *
 * Measured against the real provider while writing this: the live endpoint answers `/v1/models` with
 * one model, the same URL asked for a model it does not serve answers `offersDeclared() === false`,
 * and the package's own default answers nothing at all. These run the same four arms with the network
 * seam injected, so CI proves them without a provider.
 */
final class ProviderReachTest extends TestCase
{
    private const CATALOGUE = '{"data":[{"id":"qwen3.8-27b","meta":{"n_ctx":32768}},{"id":"llama3.2:3b"}]}';

    public function testItSaysWhatAnsweredAndWhichModelsItOffers(): void
    {
        $reach = new ProviderReach('http://provider.test:11438', 'qwen3.8-27b', self::says(self::CATALOGUE));

        self::assertTrue($reach->reached());
        self::assertSame(['qwen3.8-27b', 'llama3.2:3b'], $reach->models(), 'ids, in the order offered');
        self::assertTrue($reach->offersDeclared());
        self::assertSame('http://provider.test:11438', $reach->endpoint());
    }

    /**
     * 🚨 THE ARM NOBODY WAS CHECKING: a provider that answers, and does not serve the configured model.
     *
     * Every turn would fail at the provider and the failure would look like a bug in the turn. Config
     * says one thing, the server serves another, and until now nothing compared them.
     */
    public function testAProviderThatAnswersButDoesNotServeTheDeclaredModelSaysSo(): void
    {
        $reach = new ProviderReach('http://provider.test:11438', 'gpt-4o', self::says(self::CATALOGUE));

        self::assertTrue($reach->reached(), 'it answered');
        self::assertFalse($reach->offersDeclared(), 'and it does not serve what this house declared');
        self::assertNotContains('gpt-4o', $reach->models());
    }

    /**
     * `null` IS NOT `false`, and the difference is the whole point.
     *
     * Unreached, or nothing declared, means the question was never answerable. `false` means it was
     * answered and the answer is bad news. A surface that collapsed them would tell somebody with a
     * dead endpoint that their model name is wrong.
     */
    public function testUnansweredIsNullAndNotFalse(): void
    {
        $dead = new ProviderReach('http://nothing.test:11438', 'qwen3.8-27b', self::says(null));
        self::assertFalse($dead->reached());
        self::assertSame([], $dead->models());
        self::assertNull($dead->offersDeclared(), 'never asked, not answered badly');

        $undeclared = new ProviderReach('http://provider.test:11438', '', self::says(self::CATALOGUE));
        self::assertTrue($undeclared->reached());
        self::assertNull($undeclared->offersDeclared(), 'nothing declared is nothing to compare');
    }

    /**
     * AN EMPTY BASE URL NEVER KNOCKS, and this asserts the seam was not called at all.
     *
     * Inventing a default here is exactly how this package came to name somebody else's machine in a
     * fallback, so «no endpoint» must reach for nothing rather than reach for a guess.
     */
    public function testWithNoEndpointItKnocksOnNothing(): void
    {
        $asked = [];
        $reach = new ProviderReach('', 'qwen3.8-27b', static function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return self::CATALOGUE;
        });

        self::assertFalse($reach->reached());
        self::assertSame([], $asked, 'not one request, and no invented host');
    }

    /** A seam that throws is still just a provider that did not answer. */
    public function testAFetcherThatThrowsIsStillJustAProviderThatDidNotAnswer(): void
    {
        $reach = new ProviderReach('http://provider.test:11438', 'qwen3.8-27b', static function (): ?string {
            throw new \RuntimeException('DNS said no');
        });

        self::assertFalse($reach->reached());
        self::assertSame([], $reach->models());
    }

    /**
     * REACHED AND OFFERING NOTHING IS A FACT, not a failure — and it is a different one from unreached.
     *
     * A provider that answers with an empty catalogue is up and has nothing loaded. Telling somebody
     * their endpoint is unreachable would send them to their network instead of to their provider.
     */
    public function testAProviderThatAnswersWithNoCatalogueIsReachedAndOffersNothing(): void
    {
        foreach (['{"data":[]}', '{"object":"list"}', '{}'] as $body) {
            $reach = new ProviderReach('http://provider.test:11438', 'qwen3.8-27b', self::says($body));

            self::assertTrue($reach->reached(), "answered with $body");
            self::assertSame([], $reach->models());
            self::assertFalse($reach->offersDeclared(), 'it answered, and it does not offer it');
        }
    }

    public function testABodyThatIsNotJsonIsNotAnAnswer(): void
    {
        foreach (['<html>nope</html>', 'null', '"a string"', ''] as $body) {
            // 🚨 BRACED, or not interpolated at all. «$body» in a double-quoted string is ONE
            // variable name — the guillemets' bytes are valid PHP identifier characters, and this
            // house's prose voice reaches for them constantly. Third time in one day (greenhouse
            // decisions/0266), so it is written here as the reason and not just fixed.
            self::assertFalse((new ProviderReach('http://provider.test:11438', 'x', self::says($body)))->reached(), 'the body «' . $body . '» is not an answer');
        }
    }

    /** The other spelling in the wild is read too, and neither is required. */
    public function testItReadsTheOtherSpellingOfACatalogue(): void
    {
        $reach = new ProviderReach('http://provider.test:11438', 'mistral', self::says('{"models":[{"name":"mistral"},"raw-string-id"]}'));

        self::assertSame(['mistral', 'raw-string-id'], $reach->models());
        self::assertTrue($reach->offersDeclared());
    }

    /** Blank ids are not models, and a repeated id is one model. */
    public function testBlankIdsAreDroppedAndRepeatsCollapse(): void
    {
        $reach = new ProviderReach('http://provider.test:11438', 'a', self::says('{"data":[{"id":"a"},{"id":"  "},{"id":"a"},{"id":" b "},{"nope":1}]}'));

        self::assertSame(['a', 'b'], $reach->models(), 'trimmed, deduplicated, blanks gone');
    }

    /** A surface that paints twice must not knock twice. */
    public function testItAsksOncePerInstance(): void
    {
        $calls = 0;
        $reach = new ProviderReach('http://provider.test:11438', 'qwen3.8-27b', static function () use (&$calls): ?string {
            ++$calls;

            return self::CATALOGUE;
        });

        $reach->reached();
        $reach->models();
        $reach->offersDeclared();
        $reach->reached();

        self::assertSame(1, $calls, 'one question, however many readers');
    }

    /** Both spellings of a base URL agree, and the endpoint it reports is the normalised one. */
    public function testBothSpellingsOfTheBaseUrlKnockOnTheSameDoor(): void
    {
        $asked = [];
        $seam = static function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return self::CATALOGUE;
        };

        foreach (['http://provider.test:11438', 'http://provider.test:11438/', 'http://provider.test:11438/v1', '  http://provider.test:11438/v1/  '] as $spelling) {
            (new ProviderReach($spelling, 'qwen3.8-27b', $seam))->reached();
        }

        self::assertSame(array_fill(0, 4, 'http://provider.test:11438/v1/models'), $asked);
    }

    /** It knocks on the same door its sibling does, with the same ceiling. */
    public function testItSharesItsSiblingsDoorAndCeiling(): void
    {
        self::assertSame('/v1/models', ProviderReach::CATALOGUE);
        self::assertSame(\Milpa\AiGateway\ProviderWindow::TIMEOUT_SECONDS, ProviderReach::TIMEOUT_SECONDS);
    }

    /** @return callable(string): ?string */
    private static function says(?string $body): callable
    {
        return static fn (string $url): ?string => $body;
    }
}
