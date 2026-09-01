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
 * The caller's opinion on whether the agent's run is still making SEMANTIC progress
 * (greenhouse decisions/0185).
 *
 * The gateway drives the loop but does not own the session stream, so it cannot measure progress
 * itself — the same split {@see PlanBoard} already made for the plan: the loop asks, the caller
 * derives from the stream it owns. The fifth live run showed why tokens are the wrong unit: 811k
 * of them over 42 calls for 8 artifacts, thousands per call spent reasoning about how to write a
 * test instead of writing it. Whoever implements this measures growth in evidence,
 * materializations and closed todos — never in words.
 *
 * A probe is an OBSERVER with one lever: when it answers stalled, the orchestrator puts the
 * notice (the caller words the forced choice) in front of the model exactly once, and holds the
 * NEXT answer to it — act, declare debt, abandon the hypothesis, or the leg ends. The probe
 * never blocks a call and never rewrites history.
 */
interface ProgressProbe
{
    /**
     * Consulted after each completed step of the agent loop.
     *
     * `null` means no opinion — the loop proceeds untouched, byte-identical to running without a
     * probe. An array means the probe measured, and carries:
     *
     *  - `stalled` (bool): whether the window since the last checkpoint shows no semantic growth;
     *  - `notice` (string): the ONE system line to put in front of the model — the caller words
     *    the choice (execute an operation, `HOUSE_DEBT:`, a human decision, `ABANDON:`) and the
     *    receipt numbers backing it;
     *  - `receipt` (array): the derivation's telemetry projection, carried into the stalled
     *    sentinel so the surface can show WHY the leg ended.
     *
     * @param int $step the zero-based loop step that just completed
     *
     * @return array{stalled: bool, notice: string, receipt: array<string, mixed>}|null
     */
    public function afterStep(int $step): ?array;
}
