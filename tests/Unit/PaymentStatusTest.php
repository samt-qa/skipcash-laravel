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
    }

    #[Test]
    public function paid_never_regresses_to_an_unsuccessful_state(): void
    {
        foreach ([PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Rejected, PaymentStatus::New, PaymentStatus::Pending] as $next) {
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
    public function refunded_is_terminal(): void
    {
        foreach (PaymentStatus::cases() as $next) {
            $this->assertFalse(PaymentStatus::Refunded->canTransitionTo($next));
        }
    }
}
