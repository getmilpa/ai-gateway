<?php

/**
 * This file is part of Milpa AI Gateway.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/** Producer-owned exit observation; it grants no completion, approval, or authority. */
final readonly class RunTermination
{
    /**
     * @param array<string, mixed>|null $receipt the original progress receipt, when stalled
     * @param array<string, mixed>|null $cause   what a failed run died on, when it was the model's endpoint
     *                                           ({@see causeOf()}); null for every other ending
     */
    public function __construct(public RunEnd $reason, public ?array $receipt = null, public ?AnswerVerdict $answerVerdict = null, public ?array $cause = null)
    {
    }

    /**
     * What a failure says about the model's endpoint, or null when it was not the endpoint's.
     *
     * A run that died on its endpoint used to end as a bare `failed`: the 404 lived only in the terminal of
     * whoever ran the leg, and the panel showed a failed leg with no reason (greenhouse evidence/1069 §C1).
     * The chain is walked, because the streaming path wraps what it caught once more.
     *
     * @return array{kind: 'provider_refused', status: int, endpoint: string}|array{kind: 'provider_unreachable', endpoint: string}|null
     */
    public static function causeOf(\Throwable $error): ?array
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof ProviderRefusedException) {
                return ['kind' => 'provider_refused', 'status' => $e->status, 'endpoint' => $e->endpoint];
            }
            if ($e instanceof TransportRetryExhaustedException && $e->endpoint !== null) {
                return ['kind' => 'provider_unreachable', 'endpoint' => $e->endpoint];
            }
        }

        return null;
    }

    /** Export the reason, its optional producer receipt, and — only when there is one — the endpoint's cause.
     * @return array{reason: string, receipt: array<string, mixed>|null, answerVerdict?: array<string,mixed>, cause?: array<string,mixed>}
     */
    public function toArray(): array
    {
        $result = ['reason' => $this->reason->value, 'receipt' => $this->receipt];
        if ($this->answerVerdict !== null) {
            $result['answerVerdict'] = $this->answerVerdict->toArray();
        }
        if ($this->cause !== null) {
            $result['cause'] = $this->cause;
        }
        return $result;
    }
}
