<?php

declare(strict_types=1);

namespace Samt\SkipCash\Webhooks;

enum WebhookOutcome: string
{
    /** Status transition applied and events dispatched. */
    case Applied = 'applied';

    /** Same status already recorded; nothing to do. */
    case Unchanged = 'unchanged';

    /** This exact delivery (payment id + status) was processed before. */
    case Duplicate = 'duplicate';

    /** State machine refused the transition (e.g. paid → failed). */
    case Rejected = 'rejected';

    /** A failed retry attempt reported under a new payment id for a known order. */
    case AttemptIgnored = 'attempt_ignored';
}
