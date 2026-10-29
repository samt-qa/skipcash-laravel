<?php

declare(strict_types=1);

namespace Samt\SkipCash;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Samt\SkipCash\Client\SkipCashClient;
use Samt\SkipCash\Enums\Environment;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Payments\Applied;
use Samt\SkipCash\Payments\PaymentRecorder;
use Samt\SkipCash\Payments\Transition;
use Samt\SkipCash\Requests\CreatePayment;
use Samt\SkipCash\Responses\Payment;
use Samt\SkipCash\Responses\Refund;
use Samt\SkipCash\Responses\SavedCard;
use Samt\SkipCash\Responses\SubscriptionCancellation;
use Samt\SkipCash\Support\Amount;
use Samt\SkipCash\Support\Phone;
use Samt\SkipCash\Support\Uuid;

/**
 * The public gateway. Resolve it from the container or use the SkipCash facade.
 */
class SkipCash
{
    public function __construct(
        protected readonly SkipCashClient $client,
        protected readonly PaymentRecorder $recorder,
        protected readonly Repository $config,
        protected readonly UrlGenerator $url,
        protected readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * Create a hosted-checkout payment and record it locally.
     *
     * Redirect the shopper to `$payment->payUrl` (or open it in a WebView).
     *
     * @param  Model|null  $payable  The order/invoice this payment belongs to.
     */
    public function createPayment(CreatePayment $request, ?Model $payable = null): Payment
    {
        $request = $this->applyDefaults($request);

        $payment = Payment::fromArray(
            $this->client->createPayment($request->toArray($this->client->keyId())),
        );

        $this->recorder->recordCreated($payment, $request, $this->client->environment(), $payable);

        return $payment;
    }

    /**
     * Read a payment from SkipCash. Does not touch local state; see sync().
     */
    public function payment(string $paymentId): Payment
    {
        return Payment::fromArray($this->client->getPayment($paymentId));
    }

    /**
     * Read a payment from SkipCash and apply its status to the local record,
     * dispatching lifecycle events for any transition. Safe to call repeatedly;
     * use it on the return URL and in reconciliation.
     */
    public function sync(string $paymentId): Payment
    {
        $payment = $this->payment($paymentId);

        $this->applyLocked($paymentId, $payment);

        return $payment;
    }

    /**
     * Same as sync() but returns the transition outcome instead of the payment.
     */
    public function syncRecord(SkipCashPayment $record): Transition
    {
        $applied = $this->applyLocked($record->payment_id, $this->payment($record->payment_id));

        if ($applied === null) {
            return Transition::Unchanged;
        }

        $record->refresh();

        return $applied->transition;
    }

    /**
     * Apply a remote status under a row lock, then dispatch the events after commit.
     *
     * The return URL and a webhook routinely race for the same payment; the lock
     * serialises them so each transition, and each PaymentPaid, happens once.
     */
    protected function applyLocked(string $paymentId, Payment $payment): ?Applied
    {
        $name = $this->config->get('skipcash.database.connection');
        $connection = $this->db->connection(is_string($name) && $name !== '' ? $name : null);

        /** @var Applied|null $applied */
        $applied = $connection->transaction(function () use ($paymentId, $payment): ?Applied {
            $record = SkipCashPayment::query()->where('payment_id', $paymentId)->lockForUpdate()->first();

            return $record === null ? null : $this->recorder->applyRemoteDeferred($record, $payment);
        });

        if ($applied !== null) {
            $this->recorder->dispatch($applied->events);
        }

        return $applied;
    }

    /**
     * Refund a paid payment, fully or partially. Completion is asynchronous.
     */
    public function refund(string $paymentId, int|float|string|null $amount = null): Refund
    {
        $formatted = $amount === null ? null : Amount::format($amount);

        $body = array_filter([
            'Id' => $paymentId,
            'KeyId' => $this->client->keyId(),
            'Amount' => $formatted,
        ], static fn ($v) => $v !== null);

        $result = $this->client->refund($body);

        return Refund::fromArray($result, $paymentId, $formatted);
    }

    /**
     * Stop a recurring subscription.
     */
    public function cancelSubscription(string $subscriptionId): SubscriptionCancellation
    {
        $result = $this->client->cancelSubscription([
            'Id' => $subscriptionId,
            'KeyId' => $this->client->keyId(),
        ]);

        return SubscriptionCancellation::fromArray($result, $subscriptionId);
    }

    /**
     * Start the card-update flow for a subscription. The shopper completes a
     * QAR 5 verification payment at the returned payUrl; remaining instalments
     * then use the new card.
     */
    public function updateSubscriptionCard(
        string $subscriptionId,
        string $firstName,
        string $lastName,
        string $phone,
        string $email,
    ): Payment {
        $localCode = (string) $this->config->get('skipcash.defaults.local_country_code', '+974');

        $result = $this->client->updateCard([
            'Uid' => Uuid::v4(),
            'KeyId' => $this->client->keyId(),
            'FirstName' => $firstName,
            'LastName' => $lastName,
            'Phone' => Phone::normalize($phone, $localCode),
            'Email' => $email,
            'RecurringSubscriptionId' => $subscriptionId,
        ]);

        return Payment::fromArray($result);
    }

    /**
     * Masked details of tokenised cards, for a "choose a saved card" UI.
     *
     * @param  string|list<string>  $tokenIds
     * @return list<SavedCard>
     */
    public function savedCards(string|array $tokenIds): array
    {
        $ids = array_values(array_filter(is_array($tokenIds) ? $tokenIds : [$tokenIds]));

        if ($ids === []) {
            return [];
        }

        $rows = $this->client->cardDetails($ids);

        $cards = [];
        foreach ($rows as $index => $row) {
            $cards[] = SavedCard::fromArray($row, $ids[$index] ?? $ids[0]);
        }

        return $cards;
    }

    public function environment(): Environment
    {
        return $this->client->environment();
    }

    public function client(): SkipCashClient
    {
        return $this->client;
    }

    /**
     * Fill return/webhook URLs from config when the request did not set them.
     * Recurring payments require a webhook URL in the body to emit events.
     */
    protected function applyDefaults(CreatePayment $request): CreatePayment
    {
        $returnUrl = $this->config->get('skipcash.defaults.return_url');
        if ($request->returnUrl() === null && is_string($returnUrl) && $returnUrl !== '') {
            $request = $request->withReturnUrl($returnUrl);
        }

        $webhookUrl = $this->config->get('skipcash.defaults.webhook_url');
        if (! is_string($webhookUrl) || $webhookUrl === '') {
            $webhookUrl = $this->routeWebhookUrl();
        }

        if ($request->webhookUrl() === null && $webhookUrl !== null && ($request->isRecurring() || $this->shouldAlwaysSendWebhookUrl())) {
            $request = $request->withWebhookUrl($webhookUrl);
        }

        return $request;
    }

    protected function routeWebhookUrl(): ?string
    {
        if (! (bool) $this->config->get('skipcash.webhooks.register_route', true)) {
            return null;
        }

        try {
            return $this->url->route('skipcash.webhook');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function shouldAlwaysSendWebhookUrl(): bool
    {
        return (bool) $this->config->get('skipcash.defaults.always_send_webhook_url', true);
    }
}
