<?php

/**
 * This file is part of Milpa AI Gateway.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AiGateway;

/** The branch that ended a base orchestrator run, not a verdict about completed work. */
enum RunEnd: string
{
    case FinalAnswer = 'final_answer';
    case AnswerRejected = 'answer_rejected';
    case AnswerIndeterminate = 'answer_indeterminate';
    case ToolRefused = 'tool_refused';
    case ConfirmationRequired = 'confirmation_required';
    case Blocked = 'blocked';
    case StepsExhausted = 'steps_exhausted';
    case ContextBudgetExhausted = 'context_budget_exhausted';
    case ProgressStalled = 'progress_stalled';
    case HouseDebt = 'house_debt';
    case InvalidResponse = 'invalid_response';
    case Interrupted = 'interrupted';
    case OutputTruncated = 'output_truncated';
    case Failed = 'failed';
}
