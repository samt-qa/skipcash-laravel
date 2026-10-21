<?php

declare(strict_types=1);

namespace Samt\SkipCash\Events;

/**
 * A shopper's attempt on a known order failed, but the original payment link
 * is still valid. SkipCash reports such attempts under a *new* payment id;
 * the local record is left untouched so a later success can still land.
 */
final class PaymentAttemptFailed extends PaymentEvent {}
