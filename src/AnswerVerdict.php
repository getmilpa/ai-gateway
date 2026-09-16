<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway;

/** A finite answer judgment; acceptance grants neither work verification nor authority. */
final readonly class AnswerVerdict
{
    /** @param array<string,mixed> $evidence producer-owned criterion and evidence coordinates */
    public function __construct(
        public string $status,
        public string $candidateSha256,
        public string $reason,
        public array $evidence = [],
    ) {
        if (!in_array($status, ['accepted', 'rejected', 'indeterminate'], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $candidateSha256) || trim($reason) === '') {
            throw new \InvalidArgumentException('Invalid answer verdict.');
        }
        json_encode($evidence, JSON_THROW_ON_ERROR);
    }

    /** Export the verdict bound to the raw candidate and its producer evidence.
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'candidateSha256' => $this->candidateSha256,
            'reason' => $this->reason, 'evidence' => $this->evidence];
    }
}
