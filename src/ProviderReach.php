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

namespace Milpa\AiGateway;

/**
 * WHETHER A MODEL ANSWERS, AND WHICH ONES THE PROVIDER OFFERS — asked, never assumed.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────────────────────────
 *
 * Nothing in this framework could say «there is no reachable model». Every surface that showed one
 * READ IT FROM CONFIG: the Desktop's footer printed `Model: qwen3.8-27b` whether or not anything was
 * listening, because it resolved `agent.model` with a hardcoded fallback — and its endpoint fallback
 * named `llama.local`, a host that stopped resolving when that machine moved to Tailscale. Measured
 * 2026-09-09: `llama.tailf880b7.ts.net:11438/v1/models` answers 200, `llama.local:11438` answers 000
 * (greenhouse decisions/0266).
 *
 * So a surface asserted a model it had never asked. That is the mistake this house calls its most
 * expensive — asking the TEXT what only EXECUTION answers — committed by the one line a person reads
 * to find out what they are talking to.
 *
 * ── WHAT IT ANSWERS, INCLUDING WHAT IT CANNOT ────────────────────────────────────────────────────
 *
 * `GET /v1/models` is the portable door — the same one {@see ProviderWindow} already knocks on for
 * the context window, so this asks the question the house was already asking and reads a different
 * part of the answer. There is no second transport and no second timeout.
 *
 * Three facts come back, and the third is the one nobody thinks to want:
 *
 *   · did anything answer                — `reached()`
 *   · which models it offers             — `models()`
 *   · does it offer the one we DECLARE   — `offersDeclared()`
 *
 * A provider that answers with a catalogue that does not contain the configured model is not a
 * working house: every turn will fail at the provider, and the failure will look like a bug in the
 * turn. Config says one thing, the server serves another, and until now nothing compared them.
 *
 * ── THE PROMISE, THE SAME ONE ITS SIBLING MAKES ──────────────────────────────────────────────────
 *
 * An unanswered question, never an exception. Unreachable, non-JSON, JSON without the field, an
 * empty catalogue — each resolves as «not reached» or «none offered». The caller asked whether it
 * could talk to a model, not for a new way to die.
 *
 * WHERE THE INPUTS CAME FROM IS NOT THIS CLASS'S BUSINESS. It measures a provider; whether the base
 * URL arrived from `config/app.php`, from the environment, or from a package's default is something
 * only the caller knows, and it is exactly what a person needs in order to fix an unreachable one —
 * so the caller reports it beside this answer rather than this class guessing at it.
 */
final class ProviderReach
{
    /** The catalogue door: the same one {@see ProviderWindow} reads for `meta.n_ctx`. */
    public const CATALOGUE = '/v1/models';

    /** The same ceiling its sibling uses: a probe that hangs is a surface that hangs. */
    public const TIMEOUT_SECONDS = ProviderWindow::TIMEOUT_SECONDS;

    private readonly string $root;

    /** @var callable(string): ?string */
    private readonly mixed $fetch;

    private bool $asked = false;

    private bool $reached = false;

    /** @var list<string> */
    private array $offered = [];

    /**
     * @param string                         $baseUrl  where the provider lives — the same root
     *                                                 {@see LlmService} is given, with or without a
     *                                                 trailing `/v1`
     * @param string                         $declared the model this house says it talks to, or ''
     *                                                 when nothing was declared
     * @param null|callable(string): ?string $fetch    the network seam; the default reads over
     *                                                 HTTP/HTTPS with a short ceiling
     */
    public function __construct(
        string $baseUrl,
        private readonly string $declared = '',
        ?callable $fetch = null,
    ) {
        $this->root = self::normalise($baseUrl);
        $this->fetch = $fetch ?? self::httpFetcher();
    }

    /**
     * Whether anything answered the catalogue door with a readable JSON object.
     *
     * A base URL that is empty never reaches: there is nothing to knock on, and inventing a default
     * here is how a package ends up naming somebody else's machine.
     */
    public function reached(): bool
    {
        $this->ask();

        return $this->reached;
    }

    /**
     * Every model id the provider offers, in the order it offered them.
     *
     * Ids only — a chip a person picks from needs a name it can send back, and the rest of an entry
     * (`aliases`, `tags`, `meta`) is the provider's own vocabulary, which a caller that wants it can
     * ask for itself. Empty when nothing answered, and empty when a provider answered with no
     * catalogue: those are different facts, and {@see reached()} is what tells them apart.
     *
     * @return list<string>
     */
    public function models(): array
    {
        $this->ask();

        return $this->offered;
    }

    /**
     * Whether the provider offers the model this house DECLARED — `null` when there is nothing to
     * compare.
     *
     * `null` is not `false`, and the difference is the whole point: nothing declared, or nothing
     * reached, means the question was never answerable, while `false` means it WAS answered and the
     * answer is that this house is configured to talk to a model its provider does not serve.
     */
    public function offersDeclared(): ?bool
    {
        $this->ask();
        if ($this->declared === '' || !$this->reached) {
            return null;
        }

        return \in_array($this->declared, $this->offered, true);
    }

    /** Asked once per instance: a surface that paints twice must not knock twice. */
    private function ask(): void
    {
        if ($this->asked) {
            return;
        }
        $this->asked = true;
        if ($this->root === '') {
            return;
        }

        $doc = $this->json($this->root . self::CATALOGUE);
        if ($doc === null) {
            return;
        }
        $this->reached = true;

        // `data` is the OpenAI-shaped answer every provider this house has met returns; `models` is
        // the other spelling in the wild. Both are read, neither is required — a provider that
        // answered without a catalogue is reached and offers nothing, which is a fact and not a
        // failure.
        $rows = \is_array($doc['data'] ?? null) ? $doc['data'] : (\is_array($doc['models'] ?? null) ? $doc['models'] : []);
        foreach ($rows as $row) {
            $id = \is_array($row) ? ($row['id'] ?? $row['name'] ?? null) : $row;
            if (\is_string($id) && trim($id) !== '' && !\in_array(trim($id), $this->offered, true)) {
                $this->offered[] = trim($id);
            }
        }
    }

    /**
     * Fetch and decode, or `null` when nothing answered.
     *
     * 🚨 `null` AND NOT `[]`, and that is the one place this differs from {@see ProviderWindow::json()}
     * on purpose. Its sibling returns an empty array for every failure, which is harmless there
     * because it only ever looks for a number inside. Here the empty array is a REAL ANSWER —
     * a provider replying `{}` is up and holding nothing — so collapsing it with «did not answer»
     * would report a healthy endpoint as unreachable and send somebody to their network instead of
     * to their provider. Copying the helper verbatim carried that conflation in, and a falsifier
     * caught it (greenhouse decisions/0266).
     *
     * @return null|array<string, mixed>
     */
    private function json(string $url): ?array
    {
        try {
            $body = ($this->fetch)($url);
        } catch (\Throwable) {
            // A seam that throws is still just a provider that did not answer. Swallowed HERE and
            // nowhere else, exactly as its sibling does it.
            return null;
        }
        if (!\is_string($body) || $body === '') {
            return null;
        }
        $doc = json_decode($body, true);

        return \is_array($doc) ? $doc : null;
    }

    /** Trim the trailing slash and an explicit `/v1`, so both spellings of a base URL agree. */
    private static function normalise(string $baseUrl): string
    {
        $root = rtrim(trim($baseUrl), '/');

        return str_ends_with($root, '/v1') ? substr($root, 0, -3) : $root;
    }

    /** The production fetcher: a plain GET with a short ceiling, `null` on any failure. */
    private static function httpFetcher(): callable
    {
        return static function (string $url): ?string {
            $context = stream_context_create([
                'http' => ['timeout' => self::TIMEOUT_SECONDS, 'header' => 'User-Agent: milpa/ai-gateway'],
            ]);
            $body = @file_get_contents($url, false, $context);

            return $body === false ? null : $body;
        };
    }
}
