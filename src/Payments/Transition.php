<?php

declare(strict_types=1);

namespace Samt\SkipCash\Payments;

/**
 * Outcome of applying a remote status to a local payment record.
 */
enum Transition: string
{
    /** Status changed and events were dispatched. */
    case Applied = 'applied';

    /** Same status as before; metadata (visaId, card, token) merged silently. */
    case Unchanged = 'unchanged';

    /** Rejected by the state machine, e.g. an attempt to downgrade "paid" to "failed". */
    case Rejected = 'rejected';
}
