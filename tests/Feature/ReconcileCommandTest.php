<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Events\PaymentCanceled;
use Samt\SkipCash\Events\PaymentPaid;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Tests\TestCase;

final class ReconcileCommandTest extends TestCase
{
    private function seed(string $id, PaymentStatus $status, int $minutesAgo): SkipCashPayment
    {
        $record = SkipCashPayment::query()->create([
            'payment_id' => $id,
            'transaction_id' => 'ORD-'.$id,
            'status' => $status,
            'amount' => '100.00',
            'currency' => 'QAR',
            'environment' => 'sandbox',
        ]);

        $record->forceFill(['created_at' => Date::now()->subMinutes($minutesAgo)])->save();

        return $record;
    }

    #[Test]
    public function it_syncs_stale_open_payments_and_skips_fresh_or_closed_ones(): void
    {
        Event::fake([PaymentPaid::class, PaymentCanceled::class]);

        $this->seed('stale-paid', PaymentStatus::New, 30);
        $this->seed('stale-canceled', PaymentStatus::Pending, 30);
        $this->seed('fresh', PaymentStatus::New, 2);
        $this->seed('already-paid', PaymentStatus::Paid, 30);
        $this->seed('ancient', PaymentStatus::New, 60 * 100);

        Http::fake([
            '*/api/v1/payments/stale-paid' => Http::response($this->apiEnvelope($this->remotePayment('stale-paid', PaymentStatus::Paid))),
            '*/api/v1/payments/stale-canceled' => Http::response($this->apiEnvelope($this->remotePayment('stale-canceled', PaymentStatus::Canceled))),
        ]);

        $this->artisan('skipcash:reconcile')->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'stale-paid', 'status' => PaymentStatus::Paid->value]);
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'stale-canceled', 'status' => PaymentStatus::Canceled->value]);
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'fresh', 'status' => PaymentStatus::New->value]);
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'ancient', 'status' => PaymentStatus::New->value]);

        Event::assertDispatched(PaymentPaid::class, fn ($e) => $e->payment->payment_id === 'stale-paid' && $e->webhook === null);
        Event::assertDispatched(PaymentCanceled::class);
    }

    #[Test]
    public function a_refund_on_the_original_payment_is_promoted_to_a_transition(): void
    {
        $this->seed('p', PaymentStatus::Paid, 30);
        Http::fake(['*/api/v1/payments/p' => Http::response($this->apiEnvelope($this->remotePayment('p', PaymentStatus::Paid, ['refundId' => 'r', 'refundStatusId' => 6])))]);

        $this->artisan('skipcash:reconcile', ['--payment' => 'p'])->assertSuccessful();

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'p', 'status' => PaymentStatus::Refunded->value, 'refund_id' => 'r']);
    }

    #[Test]
    public function api_errors_are_reported_without_aborting_the_run(): void
    {
        $this->seed('bad', PaymentStatus::New, 30);
        $this->seed('good', PaymentStatus::New, 30);

        Http::fake([
            '*/api/v1/payments/bad' => Http::response(['hasError' => true, 'errorMessage' => 'boom', 'returnCode' => 500], 500),
            '*/api/v1/payments/good' => Http::response($this->apiEnvelope($this->remotePayment('good', PaymentStatus::Paid))),
        ]);

        $this->artisan('skipcash:reconcile')->assertFailed();

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'good', 'status' => PaymentStatus::Paid->value]);
    }
}
