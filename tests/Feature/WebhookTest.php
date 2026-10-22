<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Feature;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Events\PaymentAttemptFailed;
use Samt\SkipCash\Events\PaymentFailed;
use Samt\SkipCash\Events\PaymentPaid;
use Samt\SkipCash\Events\PaymentRefunded;
use Samt\SkipCash\Events\PaymentStatusChanged;
use Samt\SkipCash\Events\UnknownPaymentReceived;
use Samt\SkipCash\Events\WebhookReceived;
use Samt\SkipCash\Jobs\ProcessWebhook;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Models\SkipCashWebhookEvent;
use Samt\SkipCash\Tests\TestCase;

final class WebhookTest extends TestCase
{
    private function seedPayment(string $id = 'pay-1', PaymentStatus $status = PaymentStatus::New, string $transactionId = 'ORD-1'): SkipCashPayment
    {
        return SkipCashPayment::query()->create([
            'payment_id' => $id,
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => '100.00',
            'currency' => 'QAR',
            'environment' => 'sandbox',
        ]);
    }

    #[Test]
    public function it_rejects_a_missing_signature(): void
    {
        $this->postJson(route('skipcash.webhook'), $this->webhookBody('pay-1', PaymentStatus::Paid))
            ->assertStatus(401);
    }

    #[Test]
    public function it_rejects_a_signature_made_with_the_wrong_key(): void
    {
        $this->seedPayment();
        $body = $this->webhookBody('pay-1', PaymentStatus::Paid);

        $this->postWebhook($body, $this->webhookSignature($body, 'attacker-key'))->assertStatus(401);

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::New->value]);
        $this->assertSame(0, SkipCashWebhookEvent::query()->count());
    }

    #[Test]
    public function it_rejects_a_tampered_amount(): void
    {
        $this->seedPayment();
        $body = $this->webhookBody('pay-1', PaymentStatus::Paid);
        $signature = $this->webhookSignature($body);
        $body['Amount'] = '1.00';

        $this->postWebhook($body, $signature)->assertStatus(401);
    }

    #[Test]
    public function a_valid_paid_webhook_marks_the_payment_paid_and_fires_events(): void
    {
        Event::fake([WebhookReceived::class, PaymentStatusChanged::class, PaymentPaid::class]);
        $this->seedPayment();

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))
            ->assertOk()
            ->assertJson(['received' => true, 'outcome' => 'applied']);

        $record = SkipCashPayment::query()->where('payment_id', 'pay-1')->firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $record->status);
        $this->assertNotNull($record->paid_at);
        $this->assertSame('07082024100903646560', $record->visa_id);
        $this->assertSame('Credit Card', $record->card_type);
        $this->assertSame('400000XXXXXX2503', $record->card_number);

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(PaymentStatusChanged::class, fn ($e) => $e->from === PaymentStatus::New && $e->to === PaymentStatus::Paid);
        Event::assertDispatched(PaymentPaid::class, fn ($e) => $e->payment->payment_id === 'pay-1' && $e->webhook?->visaId === '07082024100903646560');

        $this->assertDatabaseHas('skipcash_webhook_events', ['payment_id' => 'pay-1', 'status_id' => 2, 'attempts' => 1]);
        $this->assertNotNull(SkipCashWebhookEvent::query()->first()?->processed_at);
    }

    #[Test]
    public function a_redelivered_webhook_is_acknowledged_but_not_reprocessed(): void
    {
        Event::fake([PaymentPaid::class]);
        $this->seedPayment();
        $body = $this->webhookBody('pay-1', PaymentStatus::Paid);

        $this->postWebhook($body)->assertOk()->assertJson(['outcome' => 'applied']);
        $this->postWebhook($body)->assertOk()->assertJson(['outcome' => 'duplicate']);

        Event::assertDispatchedTimes(PaymentPaid::class, 1);
        $this->assertSame(1, SkipCashWebhookEvent::query()->count());
    }

    #[Test]
    public function a_late_failed_webhook_never_downgrades_a_paid_payment(): void
    {
        Event::fake([PaymentFailed::class]);
        $this->seedPayment();

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertOk();
        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Failed))->assertOk()->assertJson(['outcome' => 'rejected']);

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::Paid->value]);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    #[Test]
    public function a_paid_webhook_arriving_after_a_failed_one_wins(): void
    {
        $this->seedPayment();

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Failed))->assertOk()->assertJson(['outcome' => 'applied']);
        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::Paid->value]);
    }

    #[Test]
    public function a_failed_retry_under_a_new_payment_id_does_not_touch_the_original_record(): void
    {
        Event::fake([PaymentAttemptFailed::class, PaymentFailed::class]);
        $this->seedPayment('pay-1', PaymentStatus::New, 'ORD-1');

        // SkipCash reports the failed attempt as a *copy* with a new id.
        $this->postWebhook($this->webhookBody('pay-1-copy', PaymentStatus::Failed, ['TransactionId' => 'ORD-1']))
            ->assertOk()
            ->assertJson(['outcome' => 'attempt_ignored']);

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::New->value]);
        $this->assertDatabaseMissing('skipcash_payments', ['payment_id' => 'pay-1-copy']);
        Event::assertDispatched(PaymentAttemptFailed::class, fn ($e) => $e->payment->payment_id === 'pay-1');
        Event::assertNotDispatched(PaymentFailed::class);

        // ...and the shopper's later success on the original link still lands.
        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertOk()->assertJson(['outcome' => 'applied']);
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::Paid->value]);
    }

    #[Test]
    public function an_unknown_paid_payment_is_recorded_rather_than_dropped(): void
    {
        Event::fake([UnknownPaymentReceived::class, PaymentPaid::class]);

        $this->postWebhook($this->webhookBody('portal-link-1', PaymentStatus::Paid, ['TransactionId' => null]))
            ->assertOk()
            ->assertJson(['outcome' => 'applied']);

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'portal-link-1', 'status' => PaymentStatus::Paid->value, 'amount' => '100.00']);
        Event::assertDispatched(UnknownPaymentReceived::class);
        Event::assertDispatched(PaymentPaid::class);
    }

    #[Test]
    public function processing_is_marked_complete_only_after_listeners_succeed(): void
    {
        $this->seedPayment();

        Event::listen(PaymentPaid::class, function (): void {
            throw new \RuntimeException('listener exploded');
        });

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertStatus(500);

        $event = SkipCashWebhookEvent::query()->firstOrFail();
        $this->assertNull($event->processed_at, 'must stay unprocessed so SkipCash\'s retry is applied');
        $this->assertNotNull($event->failed_at);
        $this->assertStringContainsString('listener exploded', (string) $event->error);

        // The status change itself was rolled back with the transaction.
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::New->value]);

        // SkipCash retries an hour later; the listener is fixed by then.
        Event::forget(PaymentPaid::class);
        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertOk()->assertJson(['outcome' => 'applied']);
        $this->assertSame(2, SkipCashWebhookEvent::query()->firstOrFail()->attempts);
    }

    #[Test]
    public function paid_webhooks_are_confirmed_against_the_api_when_enabled(): void
    {
        config()->set('skipcash.webhooks.verify_paid_via_api', true);
        $this->seedPayment();

        // First the API says the payment is still pending, then that it is paid.
        Http::fake(['*/api/v1/payments/pay-1' => Http::sequence()
            ->push($this->apiEnvelope($this->remotePayment('pay-1', PaymentStatus::Pending)))
            ->push($this->apiEnvelope($this->remotePayment('pay-1', PaymentStatus::Paid))),
        ]);

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertStatus(500);
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::New->value]);

        // SkipCash retries; the API now confirms → accepted.
        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertOk()->assertJson(['outcome' => 'applied']);
    }

    #[Test]
    public function amount_mismatch_with_the_api_is_refused(): void
    {
        config()->set('skipcash.webhooks.verify_paid_via_api', true);
        $this->seedPayment();
        Http::fake(['*/api/v1/payments/pay-1' => Http::response($this->apiEnvelope($this->remotePayment('pay-1', PaymentStatus::Paid, ['amount' => '1.00'])))]);

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertStatus(500);
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::New->value]);
    }

    #[Test]
    public function queued_mode_acknowledges_immediately_and_dispatches_a_job(): void
    {
        config()->set('skipcash.webhooks.queue', true);
        config()->set('skipcash.webhooks.queue_name', 'payments');
        Bus::fake();
        $this->seedPayment();

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertOk()->assertJson(['queued' => true]);

        Bus::assertDispatched(ProcessWebhook::class, fn (ProcessWebhook $job) => $job->payload->paymentId === 'pay-1' && $job->queue === 'payments');
        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::New->value]);
    }

    #[Test]
    public function malformed_bodies_get_a_400(): void
    {
        $body = ['Amount' => '1.00', 'StatusId' => 2, 'VisaId' => 'v'];

        $this->postWebhook($body)->assertStatus(400);
    }

    #[Test]
    public function a_rejected_late_delivery_does_not_overwrite_card_details(): void
    {
        $this->seedPayment();

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Paid))->assertOk();
        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Failed, ['VisaId' => 'other', 'CardType' => 'Debit Card']))
            ->assertOk()->assertJson(['outcome' => 'rejected']);

        $this->assertDatabaseHas('skipcash_payments', [
            'payment_id' => 'pay-1',
            'visa_id' => '07082024100903646560',
            'card_type' => 'Credit Card',
        ]);
    }

    #[Test]
    public function refund_states_follow_a_paid_payment(): void
    {
        Event::fake([PaymentRefunded::class]);
        $this->seedPayment('pay-1', PaymentStatus::Paid);

        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::PendingRefund))->assertOk()->assertJson(['outcome' => 'applied']);
        $this->postWebhook($this->webhookBody('pay-1', PaymentStatus::Refunded))->assertOk()->assertJson(['outcome' => 'applied']);

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-1', 'status' => PaymentStatus::Refunded->value]);
        Event::assertDispatched(PaymentRefunded::class);
    }
}
