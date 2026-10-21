<?php

declare(strict_types=1);

namespace Samt\SkipCash\Webhooks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Psr\Log\LoggerInterface;
use Samt\SkipCash\Client\SkipCashClient;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Events\PaymentAttemptFailed;
use Samt\SkipCash\Events\UnknownPaymentReceived;
use Samt\SkipCash\Exceptions\WebhookVerificationException;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Models\SkipCashWebhookEvent;
use Samt\SkipCash\Payments\PaymentRecorder;
use Samt\SkipCash\Payments\Transition;
use Samt\SkipCash\Responses\Payment;
use Samt\SkipCash\Support\Amount;
use Throwable;

/**
 * Turns a verified webhook into a local state change.
 *
 * Guarantees:
 *  - Idempotent: each (payment id, status) delivery is applied at most once.
 *  - At-least-once safe: a delivery is marked processed only after the handler
 *    and all listeners succeed, so a crash leads to a retry, not a lost payment.
 *  - Monotonic: "paid" is never downgraded by a late "failed"/"canceled".
 *  - Trust but verify: a "paid" webhook is confirmed against the API before
 *    PaymentPaid fires (configurable).
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly PaymentRecorder $recorder,
        private readonly SkipCashClient $client,
        private readonly ConnectionResolverInterface $db,
        private readonly Container $container,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(WebhookPayload $payload): WebhookOutcome
    {
        $event = $this->claim($payload);

        if ($event === null) {
            $this->logger->info('SkipCash: duplicate webhook ignored.', [
                'payment_id' => $payload->paymentId,
                'status' => $payload->status->label(),
            ]);

            return WebhookOutcome::Duplicate;
        }

        try {
            // Network call happens before the transaction so no row lock is held during HTTP.
            if ($payload->isPaid() && $this->shouldVerifyPaid()) {
                $this->confirmPaidWithApi($payload);
            }

            $outcome = $this->connection()->transaction(fn () => $this->handle($payload));

            $event->forceFill(['processed_at' => Date::now(), 'failed_at' => null, 'error' => null])->save();

            return $outcome;
        } catch (Throwable $e) {
            $event->forceFill(['failed_at' => Date::now(), 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }
    }

    /**
     * Register the delivery in the inbox. Returns null when already processed.
     */
    private function claim(WebhookPayload $payload): ?SkipCashWebhookEvent
    {
        $find = static fn () => SkipCashWebhookEvent::query()
            ->where('payment_id', $payload->paymentId)
            ->where('status_id', $payload->status->value)
            ->first();

        $event = $find();

        if ($event === null) {
            try {
                $event = SkipCashWebhookEvent::query()->create([
                    'payment_id' => $payload->paymentId,
                    'status_id' => $payload->status->value,
                    'transaction_id' => $payload->transactionId,
                    'payload' => $payload->raw,
                    'received_at' => Date::now(),
                ]);
            } catch (QueryException $e) {
                // Lost a race with a concurrent delivery of the same event.
                $event = $find() ?? throw $e;
            }
        }

        if ($event->isProcessed()) {
            return null;
        }

        $event->increment('attempts');

        return $event;
    }

    private function handle(WebhookPayload $payload): WebhookOutcome
    {
        $record = SkipCashPayment::query()
            ->where('payment_id', $payload->paymentId)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            $record = $this->adopt($payload);

            if ($record === null) {
                return WebhookOutcome::AttemptIgnored;
            }
        }

        return match ($this->recorder->applyWebhook($record, $payload)) {
            Transition::Applied => WebhookOutcome::Applied,
            Transition::Unchanged => WebhookOutcome::Unchanged,
            Transition::Rejected => WebhookOutcome::Rejected,
        };
    }

    /**
     * Decide what to do with a payment id we have never seen.
     *
     * SkipCash reports each failed retry on an existing link under a brand-new
     * payment id. If the order is known, surface the attempt and keep the
     * original record intact. Otherwise create a record so the money is tracked.
     */
    private function adopt(WebhookPayload $payload): ?SkipCashPayment
    {
        $known = $payload->transactionId === null
            ? null
            : SkipCashPayment::query()->forTransaction($payload->transactionId)->latest('id')->first();

        if ($known !== null && $payload->status->isUnsuccessful()) {
            $known->forceFill(['last_webhook_at' => Date::now()])->save();
            $this->events()->dispatch(new PaymentAttemptFailed($known, $payload));

            return null;
        }

        if ($known !== null && $known->isPaid()) {
            // Second successful charge for an order already paid under another id
            // (e.g. shopper paid twice). Track it separately; listeners decide.
            $this->logger->warning('SkipCash: additional payment received for an already-paid transaction.', [
                'transaction_id' => $payload->transactionId,
                'existing_payment_id' => $known->payment_id,
                'new_payment_id' => $payload->paymentId,
            ]);
        }

        $this->events()->dispatch(new UnknownPaymentReceived($payload));

        return $this->recorder->recordFromWebhook($payload, $this->client->environment());
    }

    private function confirmPaidWithApi(WebhookPayload $payload): void
    {
        $remote = Payment::fromArray($this->client->getPayment($payload->paymentId));

        if ($remote->status !== PaymentStatus::Paid && ! $remote->status->isRefundRelated()) {
            throw WebhookVerificationException::statusMismatch($payload->paymentId, $remote->status->label());
        }

        if (! Amount::equals($remote->amount, $payload->amount)) {
            throw WebhookVerificationException::amountMismatch($payload->paymentId, $remote->amount, $payload->amount);
        }
    }

    private function events(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }

    private function shouldVerifyPaid(): bool
    {
        return (bool) $this->config->get('skipcash.webhooks.verify_paid_via_api', true);
    }

    private function connection(): \Illuminate\Database\ConnectionInterface
    {
        $name = $this->config->get('skipcash.database.connection');

        return $this->db->connection(is_string($name) && $name !== '' ? $name : null);
    }
}
