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

namespace Milpa\AiGateway;

/**
 * Asks a provider what context window it ALLOCATED, and answers in tokens or `null`.
 *
 * ── WHY ASK AT ALL ───────────────────────────────────────────────────────────────────────────────
 *
 * Until now the number that governs compaction was a human assertion that nothing verified: an app
 * declared `agent.contextTokens` (or exported `MILPA_AGENT_CONTEXT_TOKENS`), and if nobody declared
 * anything the orchestrator gave up on a derived budget and fell back to a fixed cap. A declaration
 * that is too large is not caught by anything until the provider rejects the prompt mid-run —
 * greenhouse evidence/0443 measured exactly that: a 32,768-token model re-entered at 35.6k.
 *
 * The provider knows the answer and will say it. So the house asks (greenhouse decisions/0233).
 *
 * ── ONLY THE ALLOCATED WINDOW COUNTS, AND THE TRAP IS MEASURED ───────────────────────────────────
 *
 * Measured 2026-09-08 against `qwen3.8-27b` on llama over Tailscale:
 *
 *     GET /v1/models   → data[0].meta.n_ctx        32768   ← what the SERVER allocated
 *                        data[0].meta.n_ctx_train  262144  ← what the MODEL was trained for
 *     GET /props       → default_generation_settings.n_ctx  32768
 *
 * Eight times apart. A reader that took `n_ctx_train` would believe it had 262k of room and blow up
 * at 32k — a worse defect than not asking, because it arrives with the authority of a measurement.
 * So {@see NOT_A_CEILING} is read by NOBODY here: a number that describes the model rather than the
 * server that serves it is not a ceiling. When the training window is all a provider exposes, the
 * answer is `null` — «I could not ask», never a guess.
 *
 * ── WHY `/v1/models` FIRST AND `/props` SECOND ───────────────────────────────────────────────────
 *
 * `/v1/models` is the OpenAI-compatible surface this gateway ALREADY drives: every base URL it can
 * talk to answers under `/v1` by construction ({@see LlmService}'s `/v1/chat/completions`), so the
 * primary path asks where the provider is already known to live. `/props` is llama.cpp's own and
 * carries the same figure, which is why it is kept as the fallback — but it cannot be primary: the
 * very payload measured above declares `"endpoint_props": false` about itself, so a provider may
 * serve it, refuse it, or not have it at all. Two doors, one fact, and the portable door first.
 *
 * ── ASKING IS EGRESS ─────────────────────────────────────────────────────────────────────────────
 *
 * One question per instance, memoised — including the negative answer, because a provider that
 * stayed silent will not become talkative inside one run and re-asking would turn a missing figure
 * into a per-call network round trip (greenhouse decisions/0233, point 5).
 *
 * ── THE NETWORK IS A SEAM ────────────────────────────────────────────────────────────────────────
 *
 * The fetcher is a `callable(string): ?string`, the shape milpa/app-runtime's `CapabilityIndex`
 * established: logic that can only be exercised against a live provider is logic nobody exercises.
 */
final class ProviderWindow
{
    /** The field that answers: the window the server actually allocated for this model. */
    public const ALLOCATED = 'n_ctx';

    /**
     * The field that must NEVER be read as a ceiling: the window the model was TRAINED for.
     *
     * It is named here so the ban is greppable and so a reader of this class sees the trap rather
     * than having to know it. Measured eight times larger than {@see ALLOCATED} on the house's own
     * provider.
     */
    public const NOT_A_CEILING = 'n_ctx_train';

    /**
     * Seconds the default fetcher waits before giving up.
     *
     * Short on purpose: this question is asked to IMPROVE a budget the run already has a fallback
     * for. A provider that cannot answer in a few seconds costs more than the answer is worth, and
     * an unanswered question is a supported outcome here, not a failure.
     */
    public const TIMEOUT_SECONDS = 5;

    /** @var callable(string): ?string */
    private $fetch;

    private string $root;

    private bool $asked = false;

    private ?int $window = null;

    /**
     * @param string                         $baseUrl where the provider lives — the same root
     *                                                {@see LlmService} is given, with or without a
     *                                                trailing `/v1`
     * @param null|callable(string): ?string $fetch   the network seam; the default reads over
     *                                                HTTP/HTTPS with a short ceiling
     */
    public function __construct(string $baseUrl, ?callable $fetch = null)
    {
        $this->root = self::normalise($baseUrl);
        $this->fetch = $fetch ?? self::httpFetcher();
    }

    /**
     * The allocated context window in tokens, or `null` when the provider did not say.
     *
     * `null` covers every way the question can fail to produce a ceiling — unreachable, non-JSON,
     * JSON without the field, a value that is not a positive whole number, or a provider that
     * exposes only the training window. None of them raise: the caller asked for a better number,
     * not for a new way to die.
     */
    public function tokens(): ?int
    {
        if ($this->asked) {
            return $this->window;
        }

        $this->asked = true;
        $this->window = $this->askModels() ?? $this->askProps();

        return $this->window;
    }

    /** `GET /v1/models` → `data[0].meta.n_ctx`, the portable door. */
    private function askModels(): ?int
    {
        $doc = $this->json($this->root . '/v1/models');
        $data = \is_array($doc['data'] ?? null) ? $doc['data'] : [];

        foreach ($data as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $meta = \is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
            $window = self::positiveInt($meta[self::ALLOCATED] ?? null);
            if ($window !== null) {
                return $window;
            }
        }

        return null;
    }

    /** `GET /props` → `default_generation_settings.n_ctx`, llama.cpp's own door. */
    private function askProps(): ?int
    {
        $doc = $this->json($this->root . '/props');
        $settings = \is_array($doc['default_generation_settings'] ?? null)
            ? $doc['default_generation_settings']
            : [];

        return self::positiveInt($settings[self::ALLOCATED] ?? null);
    }

    /**
     * Fetch and decode, or an empty array — a body that is not a JSON object says nothing.
     *
     * @return array<string, mixed>
     */
    private function json(string $url): array
    {
        try {
            $body = ($this->fetch)($url);
        } catch (\Throwable) {
            // A seam that throws is still just a provider that did not answer. Swallowing it HERE
            // and nowhere else keeps the promise this class makes to its caller: an unanswered
            // question, never an exception.
            return [];
        }

        if (!\is_string($body) || $body === '') {
            return [];
        }

        $doc = json_decode($body, true);

        return \is_array($doc) ? $doc : [];
    }

    /**
     * A context window or nothing: only a positive whole number is a ceiling.
     *
     * A float, a numeric string, `0`, or a negative would each pass a loose cast and then poison
     * every share derived from it, so each resolves as «not said». `32768.0` is rejected too: a
     * provider that answers a token count as a float is answering something this reader was not
     * built to interpret, and guessing at its intent is exactly what this class refuses to do.
     */
    private static function positiveInt(mixed $value): ?int
    {
        return \is_int($value) && $value > 0 ? $value : null;
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
