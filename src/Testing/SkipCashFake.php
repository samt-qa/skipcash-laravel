<?php

declare(strict_types=1);

namespace Samt\SkipCash\Testing;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use Samt\SkipCash\Client\SkipCashClient;
use Samt\SkipCash\Enums\CardBrand;
use Samt\SkipCash\Enums\Environment;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Payments\PaymentRecorder;
use Samt\SkipCash\Payments\Transition;
use Samt\SkipCash\Requests\CreatePayment;
use Samt\SkipCash\Responses\Payment;
use Samt\SkipCash\Responses\Refund;
use Samt\SkipCash\Responses\SavedCard;
use Samt\SkipCash\Responses\SubscriptionCancellation;
use Samt\SkipCash\SkipCash;
use Samt\SkipCash\Support\Amount;
use Samt\SkipCash\Support\Uuid;

/**
 * In-memory stand-in for the gateway. No HTTP calls are made.
 *
 *   $fake = SkipCash::fake();
 *   // ... exercise your checkout ...
 *   $fake->assertPaymentCreated(fn (CreatePayment $r) => $r->amount === '250.00');
 *   $fake->markPaid($payment->id);   // simulates a successful webhook
 */
final class SkipCashFake extends SkipCash
{
    /** @var list<array{request: CreatePayment, payment: Payment}> */
    private array $created = [];

    /** @var list<array{paymentId: string, amount: ?string}> */
    private array $refunds = [];

    /** @var list<string> */
    private array $cancelledSubscriptions = [];

    /** @var array<string, Payment> */
    private array $payments = [];

    public function __construct(
        SkipCashClient $client,
        PaymentRecorder $recorder,
        Repository $config,
        UrlGenerator $url,
        ConnectionResolverInterface $db,
    ) {
        parent::__construct($client, $recorder, $config, $url, $db);
    }

    public function createPayment(CreatePayment $request, ?Model $payable = null): Payment
    {
        $payment = $this->makePayment($request->amount, $request->transactionId, $request->customFields());

        $this->created[] = ['request' => $request, 'payment' => $payment];
        $this->payments[$payment->id] = $payment;

        $this->recorder->recordCreated($payment, $request, $this->environment(), $payable);

        return $payment;
    }

    public function payment(string $paymentId): Payment
    {
        return $this->payments[$paymentId] ?? throw new \OutOfBoundsException("Fake has no payment [{$paymentId}].");
    }

    public function sync(string $paymentId): Payment
    {
        $payment = $this->payment($paymentId);
        $record = SkipCashPayment::query()->where('payment_id', $paymentId)->first();

        if ($record !== null) {
            $this->recorder->applyRemote($record, $payment);
        }

        return $payment;
    }

    public function syncRecord(SkipCashPayment $record): Transition
    {
        return $this->recorder->applyRemote($record, $this->payment($record->payment_id));
    }

    public function refund(string $paymentId, int|float|string|null $amount = null): Refund
    {
        $formatted = $amount === null ? null : Amount::format($amount);
        $this->refunds[] = ['paymentId' => $paymentId, 'amount' => $formatted];

        $this->setStatus($paymentId, PaymentStatus::PendingRefund);

        return new Refund(Uuid::v4(), $paymentId, $formatted, []);
    }

    public function cancelSubscription(string $subscriptionId): SubscriptionCancellation
    {
        $this->cancelledSubscriptions[] = $subscriptionId;

        return new SubscriptionCancellation($subscriptionId, true, 'Subscription cancel', []);
    }

    public function updateSubscriptionCard(string $subscriptionId, string $firstName, string $lastName, string $phone, string $email): Payment
    {
        return $this->makePayment('5.00', null, []);
    }

    /**
     * @param  string|list<string>  $tokenIds
     * @return list<SavedCard>
     */
    public function savedCards(string|array $tokenIds): array
    {
        $ids = is_array($tokenIds) ? $tokenIds : [$tokenIds];

        return array_map(fn (string $id) => new SavedCard($id, '1111', CardBrand::Visa, '12/2030'), $ids);
    }

    public function environment(): Environment
    {
        return Environment::tryFrom((string) $this->config->get('skipcash.environment', 'sandbox')) ?? Environment::Sandbox;
    }

    // ------------------------------------------------------------------ //
    //  Simulation helpers
    // ------------------------------------------------------------------ //

    /** Simulate SkipCash confirming the payment; dispatches PaymentPaid. */
    public function markPaid(string $paymentId): void
    {
        $this->setStatus($paymentId, PaymentStatus::Paid, ['visaId' => (string) random_int(10 ** 15, 10 ** 16 - 1), 'cardType' => 'Credit Card']);
    }

    public function markFailed(string $paymentId): void
    {
        $this->setStatus($paymentId, PaymentStatus::Failed);
    }

    public function markCanceled(string $paymentId): void
    {
        $this->setStatus($paymentId, PaymentStatus::Canceled);
    }

    public function markRefunded(string $paymentId): void
    {
        $this->setStatus($paymentId, PaymentStatus::Refunded);
    }

    // ------------------------------------------------------------------ //
    //  Assertions
    // ------------------------------------------------------------------ //

    /**
     * @param  (Closure(CreatePayment, Payment): bool)|null  $callback
     */
    public function assertPaymentCreated(?Closure $callback = null): void
    {
        Assert::assertNotEmpty($this->created, 'No SkipCash payment was created.');

        if ($callback === null) {
            return;
        }

        $matched = array_filter($this->created, static fn (array $entry): bool => (bool) $callback($entry['request'], $entry['payment']));

        Assert::assertNotEmpty($matched, 'No SkipCash payment matched the given callback.');
    }

    public function assertNothingCreated(): void
    {
        Assert::assertEmpty($this->created, 'Expected no SkipCash payment to be created, but '.count($this->created).' were.');
    }

    public function assertPaymentCount(int $expected): void
    {
        Assert::assertCount($expected, $this->created);
    }

    public function assertRefunded(string $paymentId, int|float|string|null $amount = null): void
    {
        $expected = $amount === null ? null : Amount::format($amount);

        $matched = array_filter(
            $this->refunds,
            static fn (array $refund): bool => $refund['paymentId'] === $paymentId && ($expected === null || $refund['amount'] === $expected),
        );

        Assert::assertNotEmpty($matched, "Payment [{$paymentId}] was not refunded".($expected === null ? '' : " for {$expected}").'.');
    }

    public function assertSubscriptionCancelled(string $subscriptionId): void
    {
        Assert::assertContains($subscriptionId, $this->cancelledSubscriptions, "Subscription [{$subscriptionId}] was not cancelled.");
    }

    /**
     * @return list<Payment>
     */
    public function createdPayments(): array
    {
        return array_column($this->created, 'payment');
    }

    // ------------------------------------------------------------------ //

    /**
     * @param  array<int, string>  $custom
     */
    private function makePayment(string $amount, ?string $transactionId, array $custom): Payment
    {
        $id = Uuid::v4();

        $raw = [
            'id' => $id,
            'statusId' => PaymentStatus::New->value,
            'created' => now()->toIso8601ZuluString(),
            'payUrl' => 'https://pay.skipcash.test/pay/'.$id,
            'amount' => $amount,
            'currency' => 'QAR',
            'transactionId' => $transactionId,
            'status' => 'new',
        ];

        foreach ($custom as $slot => $value) {
            $raw["custom{$slot}"] = $value;
        }

        return Payment::fromArray($raw);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function setStatus(string $paymentId, PaymentStatus $status, array $extra = []): void
    {
        $current = $this->payment($paymentId);

        $raw = array_merge($current->raw, $extra, [
            'statusId' => $status->value,
            'status' => $status->label(),
            'finishedDate' => now()->toIso8601ZuluString(),
        ]);

        $this->payments[$paymentId] = Payment::fromArray($raw);

        $record = SkipCashPayment::query()->where('payment_id', $paymentId)->first();

        if ($record !== null) {
            $this->recorder->applyRemote($record, $this->payments[$paymentId]);
        }
    }
}
