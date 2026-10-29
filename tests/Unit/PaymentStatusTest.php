<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Samt\SkipCash\Enums\PaymentStatus;

final class PaymentStatusTest extends TestCase
{
    #[Test]
    public function ids_match_the_documentation(): void
    {
        $this->assertSame(0, PaymentStatus::New->value);
        $this->assertSame(1, PaymentStatus::Pending->value);
        $this->assertSame(2, PaymentStatus::Paid->value);
        $this->assertSame(3, PaymentStatus::Canceled->value);
        $this->assertSame(4, PaymentStatus::Failed->value);
        $this->assertSame(5, PaymentStatus::Rejected->value);
        $this->assertSame(6, PaymentStatus::Refunded->value);
        $this->assertSame(7, PaymentStatus::PendingRefund->value);
        $this->assertSame(8, PaymentStatus::RefundFailed->value);
        $this->assertSame(12, PaymentStatus::InProgress->value);
    }

    #[Test]
    public function in_progress_is_open_and_may_reset_or_complete(): void
    {
        $this->assertTrue(PaymentStatus::InProgress->isOpen());
        $this->assertContains(12, PaymentStatus::openValues());
        $this->assertTrue(PaymentStatus::New->canTransitionTo(PaymentStatus::InProgress));
        $this->assertTrue(PaymentStatus::InProgress->canTransitionTo(PaymentStatus::New), 'SkipCash resets 12 → 0 after 2 minutes');
        $this->assertTrue(PaymentStatus::InProgress->canTransitionTo(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::InProgress->canTransitionTo(PaymentStatus::Failed));
        $this->assertFalse(PaymentStatus::Paid->canTransitionTo(PaymentStatus::InProgress));
    }

    #[Test]
    public function paid_never_regresses_to_an_unsuccessful_state(): void
    {
        foreach ([PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Rejected, PaymentStatus::New, PaymentStatus::Pending, PaymentStatus::InProgress] as $next) {
            $this->assertFalse(PaymentStatus::Paid->canTransitionTo($next), "paid → {$next->label()} must be rejected");
        }
    }

    #[Test]
    public function paid_can_move_into_refund_states(): void
    {
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::PendingRefund));
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Refunded));
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::RefundFailed));
    }

    #[Test]
    public function a_late_paid_webhook_overrides_an_earlier_failure(): void
    {
        $this->assertTrue(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::Canceled->canTransitionTo(PaymentStatus::Paid));
        $this->assertFalse(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Canceled));
    }

    #[Test]
    public function open_states_accept_anything(): void
    {
        foreach (PaymentStatus::cases() as $next) {
            if ($next === PaymentStatus::New) {
                continue;
            }
            $this->assertTrue(PaymentStatus::New->canTransitionTo($next));
        }
    }

    #[Test]
    public function refunded_only_accepts_a_further_partial_refund(): void
    {
        foreach (PaymentStatus::cases() as $next) {
            if ($next === PaymentStatus::PendingRefund) {
                $this->assertTrue(PaymentStatus::Refunded->canTransitionTo($next));

                continue;
            }

            $this->assertFalse(PaymentStatus::Refunded->canTransitionTo($next), "refunded → {$next->label()} must be rejected");
        }
    }
}
