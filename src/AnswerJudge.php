<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AiGateway;

/** Caller-owned evidence judges a raw candidate, independently of progress (greenhouse 0418). */
interface AnswerJudge
{
    /** Judge this candidate against an explicit contract and independently observed evidence. */
    public function judge(string $candidate): AnswerVerdict;
}
