<?php

declare(strict_types=1);

namespace Samt\SkipCash\Payments;

use Samt\SkipCash\Enums\PaymentStatus;

/**
 * Result of applying a status to a local record, with the lifecycle events
 * that belong to that transition but have not been dispatched yet.
 *
 * Dispatch them with PaymentRecorder::dispatch() once the surrounding
 * database transaction has committed, so listeners (queued or not) never
 * observe a state that may still be rolled back.
 */
final class Applied
{
    /**
     * @param  list<object>  $events
     */
    public function __construct(
        public readonly Transition $transition,
        public readonly PaymentStatus $from,
        public readonly PaymentStatus $to,
        public readonly array $events,
    ) {}
}
