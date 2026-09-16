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
    /** @param array<string, mixed>|null $receipt the original progress receipt, when stalled */
    public function __construct(public RunEnd $reason, public ?array $receipt = null, public ?AnswerVerdict $answerVerdict = null)
    {
    }

    /** Export the reason and its optional producer receipt.
     * @return array{reason: string, receipt: array<string, mixed>|null, answerVerdict?: array<string,mixed>}
     */
    public function toArray(): array
    {
        $result = ['reason' => $this->reason->value, 'receipt' => $this->receipt];
        if ($this->answerVerdict !== null) {
            $result['answerVerdict'] = $this->answerVerdict->toArray();
        }
        return $result;
    }
}
