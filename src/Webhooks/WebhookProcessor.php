<?php

declare(strict_types=1);

namespace Samt\SkipCash\Webhooks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
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
 *  - Idempotent: a redelivery that would not change the record is acknowledged
 *    and skipped; a transition is applied at most once.
 *  - At-least-once safe: the transition commits first, then the lifecycle events
 *    are dispatched, and only then is the delivery marked processed. If a
 *    listener fails, the retry replays the events without touching the record.
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

            /** @var array{WebhookOutcome, list<object>} $result */
            $result = $this->connection()->transaction(fn () => $this->handle($event, $payload));
            [$outcome, $events] = $result;

            // Listeners run after the commit: a queued listener that starts at once
            // sees the new status, and a sync listener that throws cannot roll it back.
            $this->recorder->dispatch($events);

            $event->forceFill(['processed_at' => Date::now(), 'failed_at' => null, 'error' => null])->save();

            return $outcome;
        } catch (Throwable $e) {
            try {
                $event->forceFill(['failed_at' => Date::now(), 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            } catch (Throwable $saveFailure) {
                $this->logger->error('SkipCash: could not record webhook failure.', ['payment_id' => $payload->paymentId, 'reason' => $saveFailure->getMessage()]);
            }

            throw $e;
        }
    }

    /**
     * Register the delivery in the inbox. Returns null when it is a redelivery
     * of something already processed that would not change the record.
     *
     * The same (payment, status) can legitimately arrive more than once, e.g.
     * "pending refund" for each partial refund, so duplicates are recognised by
     * their effect rather than by a unique key: if the latest processed delivery
     * has this status and the record cannot move to it again, it is a duplicate.
     */
    private function claim(WebhookPayload $payload): ?SkipCashWebhookEvent
    {
        $latest = SkipCashWebhookEvent::query()
            ->where('payment_id', $payload->paymentId)
            ->where('status_id', $payload->status->value)
            ->latest('id')
            ->first();

        if ($latest !== null && ! $latest->isProcessed()) {
            // A retry of a delivery that failed (or is being retried by the queue).
            $latest->increment('attempts');

            return $latest;
        }

        if ($latest !== null && ! $this->couldChangeRecord($payload)) {
            return null;
        }

        $event = SkipCashWebhookEvent::query()->create([
            'payment_id' => $payload->paymentId,
            'status_id' => $payload->status->value,
            'transaction_id' => $payload->transactionId,
            'payload' => $payload->raw,
            'received_at' => Date::now(),
            'attempts' => 1,
        ]);

        return $event;
    }

    /**
     * Whether applying this status would be anything other than a no-op.
     */
    private function couldChangeRecord(WebhookPayload $payload): bool
    {
        $record = SkipCashPayment::query()->where('payment_id', $payload->paymentId)->first();

        if ($record === null) {
            // Already processed once but never adopted: it was a failed attempt on
            // a known order. A repeat carries nothing new.
            return false;
        }

        return $record->status !== $payload->status && $record->status->canTransitionTo($payload->status);
    }

    /**
     * @return array{WebhookOutcome, list<object>}
     */
    private function handle(SkipCashWebhookEvent $event, WebhookPayload $payload): array
    {
        $record = SkipCashPayment::query()
            ->where('payment_id', $payload->paymentId)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            $record = $this->adopt($payload);

            if ($record === null) {
                return [WebhookOutcome::AttemptIgnored, []];
            }
        }

        $applied = $this->recorder->applyWebhookDeferred($record, $payload);

        if ($applied->transition === Transition::Applied) {
            $event->forceFill(['from_status_id' => $applied->from->value])->save();

            return [WebhookOutcome::Applied, $applied->events];
        }

        if ($applied->transition === Transition::Unchanged && $event->from_status_id !== null) {
            // A previous attempt committed this transition but its listeners failed.
            // Replay the events so they get their retry.
            $from = PaymentStatus::from($event->from_status_id);

            return [WebhookOutcome::Applied, $this->recorder->eventsFor($record, $from, $record->status, $payload)];
        }

        return [
            $applied->transition === Transition::Rejected ? WebhookOutcome::Rejected : WebhookOutcome::Unchanged,
            [],
        ];
    }

    /**
     * Decide what to do with a payment id we have never seen.
     *
     * SkipCash reports each failed retry on an existing link under a brand-new
     * payment id. If the order is known and still open, surface the attempt and
     * keep the original record intact. Anything else (a failed recurring
     * instalment, a failure after the order was already paid, a portal payment
     * link) gets its own record so the money, or its absence, is tracked.
     */
    private function adopt(WebhookPayload $payload): ?SkipCashPayment
    {
        $known = $payload->transactionId === null
            ? null
            : SkipCashPayment::query()->forTransaction($payload->transactionId)->latest('id')->first();

        $isRetryOnOpenLink = $known !== null
            && $known->isOpen()
            && $payload->status->isUnsuccessful()
            && $payload->recurringSubscriptionId === null;

        if ($isRetryOnOpenLink) {
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

    private function connection(): ConnectionInterface
    {
        $name = $this->config->get('skipcash.database.connection');

        return $this->db->connection(is_string($name) && $name !== '' ? $name : null);
    }
}
