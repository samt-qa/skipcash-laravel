<?php

declare(strict_types=1);

namespace Samt\SkipCash\Payments;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Psr\Log\LoggerInterface;
use Samt\SkipCash\Enums\Environment;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Events\PaymentCanceled;
use Samt\SkipCash\Events\PaymentFailed;
use Samt\SkipCash\Events\PaymentPaid;
use Samt\SkipCash\Events\PaymentRefunded;
use Samt\SkipCash\Events\PaymentStatusChanged;
use Samt\SkipCash\Events\RefundFailed;
use Samt\SkipCash\Events\RefundPending;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Requests\CreatePayment;
use Samt\SkipCash\Responses\Payment;
use Samt\SkipCash\Webhooks\WebhookPayload;

/**
 * Single writer for the local payments table.
 *
 * Every status change, whether it comes from a webhook or from reconciliation,
 * passes through apply(), which enforces the state machine (a paid payment
 * never regresses) and dispatches the lifecycle events exactly once.
 */
final class PaymentRecorder
{
    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {}

    /** Resolved per dispatch so Event::fake() applies even after this recorder was built. */
    private function events(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }

    /**
     * Persist a freshly created payment.
     */
    public function recordCreated(Payment $payment, CreatePayment $request, Environment $environment, ?Model $payable = null): SkipCashPayment
    {
        $custom = $request->customFields();

        return SkipCashPayment::query()->create([
            'payment_id' => $payment->id,
            'transaction_id' => $payment->transactionId ?? $request->transactionId,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'environment' => $environment->value,
            'pay_url' => $payment->payUrl,
            'recurring_subscription_id' => $payment->recurringSubscriptionId,
            'custom' => $custom === [] ? null : $custom,
            'payable_type' => $payable?->getMorphClass(),
            'payable_id' => $payable?->getKey(),
            'raw' => $payment->raw,
        ]);
    }

    /**
     * Create a local record for a payment first seen through a webhook.
     */
    public function recordFromWebhook(WebhookPayload $payload, Environment $environment): SkipCashPayment
    {
        return SkipCashPayment::query()->create([
            'payment_id' => $payload->paymentId,
            'transaction_id' => $payload->transactionId,
            'status' => PaymentStatus::New,
            'amount' => $payload->amount,
            'currency' => 'QAR',
            'environment' => $environment->value,
            'custom' => array_filter($payload->custom, static fn ($v) => $v !== null) ?: null,
        ]);
    }

    /**
     * Apply a status reported by a webhook.
     */
    public function applyWebhook(SkipCashPayment $record, WebhookPayload $payload): Transition
    {
        $applied = $this->applyWebhookDeferred($record, $payload);
        $this->dispatch($applied->events);

        return $applied->transition;
    }

    /**
     * Same as applyWebhook() but leaves the events for the caller to dispatch after commit.
     */
    public function applyWebhookDeferred(SkipCashPayment $record, WebhookPayload $payload): Applied
    {
        return $this->applyDeferred($record, $payload->status, [
            'visa_id' => $payload->visaId,
            'token_id' => $payload->tokenId,
            'card_type' => $payload->cardType,
            'card_number' => $payload->cardNumber,
            'recurring_subscription_id' => $payload->recurringSubscriptionId,
            'last_webhook_at' => Date::now(),
        ], $payload);
    }

    /**
     * Apply a status read from GET /api/v1/payments/{id}.
     */
    public function applyRemote(SkipCashPayment $record, Payment $payment): Transition
    {
        $applied = $this->applyRemoteDeferred($record, $payment);
        $this->dispatch($applied->events);

        return $applied->transition;
    }

    /**
     * Same as applyRemote() but leaves the events for the caller to dispatch after commit.
     */
    public function applyRemoteDeferred(SkipCashPayment $record, Payment $payment): Applied
    {
        $attributes = [
            'visa_id' => $payment->visaId,
            'token_id' => $payment->tokenId,
            'card_type' => $payment->cardType,
            'card_number' => $payment->cardNumber,
            'recurring_subscription_id' => $payment->recurringSubscriptionId,
            'refund_id' => $payment->refundId,
            'refund_status' => $payment->refundStatus,
            'finished_at' => $payment->finishedAt,
            'pay_url' => $payment->payUrl,
            'last_synced_at' => Date::now(),
            'raw' => $payment->raw,
        ];

        // A refund on the *original* payment surfaces as refundStatusId while
        // statusId stays "paid". Promote it so listeners see a real transition.
        $status = $payment->status === PaymentStatus::Paid && $payment->refundStatus?->isRefundRelated()
            ? $payment->refundStatus
            : $payment->status;

        return $this->applyDeferred($record, $status, $attributes, null);
    }

    /**
     * Apply a status and dispatch the resulting events immediately.
     *
     * Only use this outside a database transaction. Inside one, call
     * applyDeferred() and dispatch() the events after the commit.
     *
     * @param  array<string, mixed>  $attributes  Metadata to merge; nulls never overwrite existing values.
     */
    public function apply(SkipCashPayment $record, PaymentStatus $to, array $attributes, ?WebhookPayload $source): Transition
    {
        $applied = $this->applyDeferred($record, $to, $attributes, $source);
        $this->dispatch($applied->events);

        return $applied->transition;
    }

    /**
     * Apply a status to the record without dispatching anything.
     *
     * @param  array<string, mixed>  $attributes  Metadata to merge; nulls never overwrite existing values.
     */
    public function applyDeferred(SkipCashPayment $record, PaymentStatus $to, array $attributes, ?WebhookPayload $source): Applied
    {
        $from = $record->status;

        if ($from !== $to && ! $from->canTransitionTo($to)) {
            // Keep the record's card/visa data intact: a stale "failed" delivery
            // must not overwrite details of the successful capture. Only stamp it.
            foreach (['last_webhook_at', 'last_synced_at'] as $stamp) {
                if (($attributes[$stamp] ?? null) !== null) {
                    $record->{$stamp} = $attributes[$stamp];
                }
            }
            $record->save();

            $this->logger->warning('SkipCash: ignored status regression.', [
                'payment_id' => $record->payment_id,
                'transaction_id' => $record->transaction_id,
                'from' => $from->label(),
                'to' => $to->label(),
                'source' => $source === null ? 'reconcile' : 'webhook',
            ]);

            return new Applied(Transition::Rejected, $from, $to, []);
        }

        foreach ($attributes as $key => $value) {
            if ($value !== null) {
                $record->{$key} = $value;
            }
        }

        if ($from === $to) {
            $record->save();

            return new Applied(Transition::Unchanged, $from, $to, []);
        }

        $record->status = $to;

        if ($to === PaymentStatus::Paid) {
            $record->paid_at ??= Date::now();
        }

        $record->save();

        return new Applied(Transition::Applied, $from, $to, $this->eventsFor($record, $from, $to, $source));
    }

    /**
     * The lifecycle events that announce a transition, in dispatch order.
     * Used again to replay a delivery whose listeners failed after commit.
     *
     * @return list<object>
     */
    public function eventsFor(SkipCashPayment $record, PaymentStatus $from, PaymentStatus $to, ?WebhookPayload $source): array
    {
        $events = [new PaymentStatusChanged($record, $from, $to, $source)];

        $event = match ($to) {
            PaymentStatus::Paid => new PaymentPaid($record, $source),
            PaymentStatus::Failed, PaymentStatus::Rejected => new PaymentFailed($record, $source),
            PaymentStatus::Canceled => new PaymentCanceled($record, $source),
            PaymentStatus::Refunded => new PaymentRefunded($record, $source),
            PaymentStatus::PendingRefund => new RefundPending($record, $source),
            PaymentStatus::RefundFailed => new RefundFailed($record, $source),
            PaymentStatus::New, PaymentStatus::Pending, PaymentStatus::InProgress => null,
        };

        if ($event !== null) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @param  list<object>  $events
     */
    public function dispatch(array $events): void
    {
        $dispatcher = $this->events();

        foreach ($events as $event) {
            $dispatcher->dispatch($event);
        }
    }
}
