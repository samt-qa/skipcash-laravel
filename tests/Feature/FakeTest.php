<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Events\PaymentPaid;
use Samt\SkipCash\Facades\SkipCash;
use Samt\SkipCash\Requests\CreatePayment;
use Samt\SkipCash\SkipCash as Gateway;
use Samt\SkipCash\Tests\TestCase;

final class FakeTest extends TestCase
{
    #[Test]
    public function the_fake_replaces_the_gateway_for_facade_and_injection(): void
    {
        // Resolve the real gateway first: the fakes must still win afterwards.
        app(Gateway::class);

        $fake = SkipCash::fake();

        Http::fake(fn () => $this->fail('No HTTP call should be made'));
        Event::fake([PaymentPaid::class]);

        $payment = SkipCash::createPayment(CreatePayment::make(250, 'A', 'B', '55123456', 'a@b.co', transactionId: 'ORD-7'));

        $this->assertSame($fake, app(Gateway::class));
        $this->assertSame('250.00', $payment->amount);
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => $payment->id, 'transaction_id' => 'ORD-7']);

        $fake->assertPaymentCreated(fn (CreatePayment $r) => $r->transactionId === 'ORD-7' && $r->amount === '250.00');
        $fake->assertPaymentCount(1);

        $fake->markPaid($payment->id);

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => $payment->id, 'status' => PaymentStatus::Paid->value]);
        Event::assertDispatched(PaymentPaid::class);

        $fake->refund($payment->id, 50);
        $fake->assertRefunded($payment->id, 50);
    }
}
