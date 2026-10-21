# SkipCash for Laravel

[![Tests](https://github.com/samt-qa/skipcash-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/samt-qa/skipcash-laravel/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/samt/skipcash-laravel.svg)](https://packagist.org/packages/samt/skipcash-laravel)
[![License](https://img.shields.io/packagist/l/samt/skipcash-laravel.svg)](LICENSE)

A production-grade Laravel SDK for the [SkipCash](https://skipcash.app) payment gateway (Qatar).

> **Unofficial.** This package is built and maintained by [Samt](https://samt.qa) and is not affiliated with SkipCash Solutions LLC. It implements the public [SkipCash developer documentation](https://dev.skipcash.app/doc/).

- **Hosted checkout** — create a payment, redirect to `payUrl`, done.
- **Verified webhooks** — HMAC signature check, idempotent inbox, out-of-order protection, optional API confirmation before anything is marked paid.
- **Refunds**, **tokenised cards**, **recurring subscriptions**, **QPAY debit-only** flow.
- **Reconciliation command** that catches every payment whose webhook never arrived.
- **Typed everywhere** — enums, immutable request/response objects, named exceptions.
- **Test fake** — `SkipCash::fake()` with assertions; no HTTP in your test suite.
- Laravel 12 and 13 · PHP 8.2+ · MIT.

---

## Contents

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Accepting a payment](#accepting-a-payment)
4. [Reacting to payment events](#reacting-to-payment-events)
5. [The return URL](#the-return-url)
6. [Webhooks in depth](#webhooks-in-depth)
7. [Reconciliation](#reconciliation)
8. [Refunds](#refunds)
9. [Saved cards (tokenisation)](#saved-cards-tokenisation)
10. [Recurring payments](#recurring-payments)
11. [Debit cards only (QPAY)](#debit-cards-only-qpay)
12. [Mobile apps](#mobile-apps)
13. [Testing your application](#testing-your-application)
14. [Multi-tenant credentials](#multi-tenant-credentials)
15. [Going live](#going-live)
16. [Reference](#reference)

---

## Installation

```bash
composer require samt/skipcash-laravel
php artisan vendor:publish --tag=skipcash-config
php artisan migrate
```

Migrations are loaded automatically. Publish them only if you need to customise table names:

```bash
php artisan vendor:publish --tag=skipcash-migrations
```

## Configuration

Generate keys in the SkipCash Merchant Portal → **Online Payments**. Sandbox and Production each have their own set.

```dotenv
SKIPCASH_ENV=sandbox                # sandbox | production
SKIPCASH_CLIENT_ID=
SKIPCASH_KEY_ID=
SKIPCASH_KEY_SECRET=
SKIPCASH_WEBHOOK_KEY=
SKIPCASH_RETURN_URL=https://example.com/checkout/return
```

> **Test accounts:** if your merchant account itself is a test account, generate keys from the **Production** tab. Sandbox keys on a test account return `Missing or invalid key`.

The webhook URL is sent with every payment automatically (`route('skipcash.webhook')`), so the portal setting is never load-bearing. See `config/skipcash.php` for everything else.

## Accepting a payment

```php
use Samt\SkipCash\Facades\SkipCash;
use Samt\SkipCash\Requests\CreatePayment;

$payment = SkipCash::createPayment(
    CreatePayment::make(
        amount: $order->total,          // QAR — int, float or "99.50"
        firstName: $order->first_name,
        lastName: $order->last_name,
        phone: $order->phone,           // "55123456" or "+97455123456"
        email: $order->email,           // null → "{phone}@{fallback domain}"
        transactionId: $order->number,  // your reference; comes back on every webhook
    )
        ->withSubject("Order {$order->number}")
        ->withCustom(1, (string) $order->id),
    payable: $order,                    // optional morph relation
);

return redirect()->away($payment->payUrl);
```

`createPayment()` validates the request locally against the documented limits (name length, address total ≤ 50 chars, phone format, …) and throws `InvalidRequestException` before any HTTP call. API errors throw `ApiException` with `errorCode`, `validationErrors` and the raw body.

A row is written to `skipcash_payments` for every payment, keyed by SkipCash's `payment_id` and your `transaction_id`.

## Reacting to payment events

Register listeners for the events you care about. Each receives the local `SkipCashPayment` record and, when triggered by a webhook, the `WebhookPayload`.

```php
use Samt\SkipCash\Events\PaymentPaid;
use Samt\SkipCash\Events\PaymentFailed;
use Samt\SkipCash\Events\PaymentRefunded;

Event::listen(PaymentPaid::class, function (PaymentPaid $event) {
    $order = Order::where('number', $event->payment->transaction_id)->firstOrFail();
    $order->markPaid(reference: $event->payment->visa_id);
});
```

| Event | When |
|---|---|
| `PaymentPaid` | Money captured (confirmed against the API by default). |
| `PaymentFailed` | Status `failed` or `rejected`. |
| `PaymentCanceled` | Shopper abandoned; SkipCash auto-cancels after 1 hour. |
| `RefundPending`, `PaymentRefunded`, `RefundFailed` | Refund lifecycle. |
| `PaymentStatusChanged` | Every accepted transition, with `from` and `to`. |
| `PaymentAttemptFailed` | A retry on an existing link failed. The original payment is untouched and may still succeed. |
| `UnknownPaymentReceived` | A webhook for a payment this app never created (e.g. a portal Payment Link). A record is created. |
| `WebhookReceived` | Raw, before processing. For audit logs. |

Events fire **exactly once** per transition, whether the transition came from a webhook or from reconciliation. Queue your listeners if they do real work.

## The return URL

SkipCash redirects the shopper to your return URL with `?id={paymentId}`. Do not trust the redirect alone — sync the payment:

```php
Route::get('/checkout/return', function (Request $request) {
    $payment = SkipCash::sync($request->query('id'));   // GET + apply status + fire events

    return $payment->isPaid()
        ? view('checkout.thanks')
        : view('checkout.pending');
});
```

`sync()` is idempotent; calling it after the webhook already processed the payment is a no-op.

## Webhooks in depth

A route is registered at `POST /skipcash/webhook` (configurable). The pipeline:

1. **Signature** — `VerifyWebhookSignature` middleware recomputes HMAC-SHA256 over `PaymentId, Amount, StatusId, TransactionId, Custom1, VisaId` with your webhook key and compares in constant time. Mismatch → `401`, nothing is stored.
2. **Inbox** — each `(payment_id, status_id)` delivery is recorded in `skipcash_webhook_events`. Redeliveries are acknowledged with `200` and skipped.
3. **State machine** — `paid` never regresses to `failed`/`canceled`, however late those arrive. A late `paid` after `failed` wins.
4. **Verification** — for `paid`, the payment is re-read from the API and status + amount must match (`webhooks.verify_paid_via_api`). On mismatch the delivery is left unprocessed and a `500` is returned so SkipCash retries.
5. **Commit** — the transition and your listeners run inside one database transaction. The delivery is marked processed only when all of it succeeds. If a listener throws, nothing is marked paid and SkipCash's retry (1 h, then 1 d) replays the event.

The route has no middleware other than the signature check by default. Do not put it behind the `api` group: its rate limiter can answer SkipCash's retries with `429`. Add your own via `webhooks.middleware`.

### Queue it (recommended)

SkipCash waits 10 s and retries only three times. With `SKIPCASH_WEBHOOK_QUEUE=true` the controller acknowledges immediately and `ProcessWebhook` runs on your queue with its own backoff (30 s → 2 m → 10 m → 1 h, 5 attempts).

```dotenv
SKIPCASH_WEBHOOK_QUEUE=true
SKIPCASH_WEBHOOK_QUEUE_NAME=payments
```

### Registering the route yourself

Set `webhooks.register_route` to `false` and add:

```php
Route::post('/hooks/skipcash', \Samt\SkipCash\Http\Controllers\WebhookController::class)
    ->middleware(\Samt\SkipCash\Http\Middleware\VerifyWebhookSignature::class)
    ->name('skipcash.webhook');
```

Keep the route **name** so the webhook URL can be attached to outgoing payments.

## Reconciliation

Webhooks fail: deploys, network partitions, a wrong URL in the portal. The reconcile command re-reads every payment that is still `new`/`pending` locally and applies the real status through the same state machine and events:

```php
// routes/console.php
Schedule::command('skipcash:reconcile')->everyFifteenMinutes();
```

```
php artisan skipcash:reconcile                    # stale open payments
php artisan skipcash:reconcile --payment=<id>     # one payment, any age
php artisan skipcash:reconcile --dry-run          # report only
```

## Refunds

```php
$refund = SkipCash::refund($payment->payment_id);          // full
$refund = SkipCash::refund($payment->payment_id, 25.50);   // partial
```

Refunds settle asynchronously (usually within a day). You will receive `RefundPending` → `PaymentRefunded` or `RefundFailed` via webhooks; reconciliation also picks them up from `refundStatusId`.

## Saved cards (tokenisation)

The shopper ticks **Save card** on the hosted page. After a successful payment, `token_id` is stored on the local record (also on `PaymentPaid` → `$event->payment->token_id`).

```php
// Show the shopper their cards
$cards = SkipCash::savedCards($user->skipcash_tokens);   // list<SavedCard>: brand, lastFour, expiry

// Charge a saved card — shopper still confirms with OTP on the hosted page
$payment = SkipCash::createPayment(
    CreatePayment::make(...)->withToken($card->tokenId),
);
```

## Recurring payments

```php
use Samt\SkipCash\Enums\Interval;
use Samt\SkipCash\Requests\RecurringPlan;

$plan = RecurringPlan::make(
    planName: 'Gold monthly',
    interval: Interval::Month,
    startDate: now(),
    endDate: now()->addYear(),
    frequency: 1,                 // every 1 month
    allowedFailedAttempts: 3,
    firstPaymentAmount: 1,        // optional: different first charge
);

$payment = SkipCash::createPayment(CreatePayment::make(...)->recurring($plan));
```

Each instalment arrives as a webhook carrying `RecurringSubscriptionId`. The subscription id is stored on the payment record.

```php
SkipCash::cancelSubscription($subscriptionId);

// Card expired? Shopper pays QAR 5 at the returned payUrl to verify the new card.
$verification = SkipCash::updateSubscriptionCard($subscriptionId, $first, $last, $phone, $email);
```

## Debit cards only (QPAY)

Send the shopper straight to the Qatari debit-card (NAPS) flow:

```php
CreatePayment::make(...)->debitCardOnly();
```

## Mobile apps

Create the payment on your Laravel backend and return `payUrl` to the app; open it in a WebView. When the WebView closes, call your backend, which calls `SkipCash::sync($id)`. Never trust the app's view of the outcome — the webhook and `sync()` are the source of truth.

```php
Route::post('/api/checkout', function (Request $request) {
    $payment = SkipCash::createPayment(CreatePayment::make(...));

    return ['payment_id' => $payment->id, 'pay_url' => $payment->payUrl];
});
```

## Testing your application

```php
use Samt\SkipCash\Facades\SkipCash;

public function test_checkout_marks_order_paid(): void
{
    $fake = SkipCash::fake();                       // no HTTP, still writes skipcash_payments

    $this->post('/checkout', [...])->assertRedirect();

    $fake->assertPaymentCreated(fn ($request) => $request->amount === '250.00');

    $fake->markPaid($fake->createdPayments()[0]->id);   // fires PaymentPaid through the real pipeline

    $this->assertTrue(Order::first()->isPaid());
}
```

Available: `assertPaymentCreated(?Closure)`, `assertNothingCreated()`, `assertPaymentCount()`, `assertRefunded($id, $amount?)`, `assertSubscriptionCancelled($id)`, `markPaid/markFailed/markCanceled/markRefunded($id)`, `createdPayments()`.

`SkipCash::fake()` also turns off API confirmation of `paid` webhooks, so your webhook tests never call SkipCash.

To test your webhook endpoint end to end, sign a body with the `Signer`:

```php
$body = ['PaymentId' => $id, 'Amount' => '250.00', 'StatusId' => 2, 'TransactionId' => 'ORD-1', 'VisaId' => 'x'];
$signature = (new \Samt\SkipCash\Signing\Signer)->sign(\Samt\SkipCash\Signing\SignatureFields::WEBHOOK, $body, config('skipcash.credentials.webhook_key'));

$this->postJson(route('skipcash.webhook'), $body, ['Authorization' => $signature])->assertOk();
```

## Multi-tenant credentials

Each tenant owns a SkipCash merchant account? Bind your own resolver:

```php
use Samt\SkipCash\Credentials\{Credentials, CredentialsResolver};
use Samt\SkipCash\Enums\Environment;

final class TenantCredentials implements CredentialsResolver
{
    public function credentials(): Credentials
    {
        $t = tenant();
        return new Credentials($t->skipcash_client_id, $t->skipcash_key_id, $t->skipcash_key_secret, $t->skipcash_webhook_key);
    }

    public function environment(): Environment
    {
        return tenant()->skipcash_live ? Environment::Production : Environment::Sandbox;
    }
}

// AppServiceProvider::register()
$this->app->bind(CredentialsResolver::class, TenantCredentials::class);
```

The resolver is called per request, so tenant identification from the current request/route works. For webhooks, put the tenant in the webhook path or in `Custom2` and resolve it in middleware that runs before `VerifyWebhookSignature`.

## Going live

1. In the portal, **Enable Production** and generate a production key set.
2. Set `SKIPCASH_ENV=production` and the four production credentials.
3. Set `SKIPCASH_RETURN_URL` to your production return route.
4. Schedule `skipcash:reconcile`.
5. Make one real payment of QAR 1 and refund it.

## Reference

### Sandbox test cards

| Card | Number | Expiry | CVV | Result |
|---|---|---|---|---|
| Mastercard | 5200 0000 0000 2151 | 10/2028 | 237 | success |
| Visa | 4000 0000 0000 2503 | 10/2028 | 442 | success |
| Mastercard | 5200 0000 0000 2490 | 04/2028 | 256 | failure |
| Visa | 4000 0000 0000 2370 | 06/2028 | 256 | failure |
| NAPS debit | 4215 3755 0088 3243 | 06/2026 | 123 | OTP `123456` |

### Payment statuses

| `statusId` | Enum | Meaning |
|---|---|---|
| 0 | `PaymentStatus::New` | created |
| 1 | `PaymentStatus::Pending` | awaiting shopper |
| 2 | `PaymentStatus::Paid` | captured |
| 3 | `PaymentStatus::Canceled` | abandoned / expired |
| 4 | `PaymentStatus::Failed` | declined |
| 5 | `PaymentStatus::Rejected` | rejected by risk |
| 6 | `PaymentStatus::Refunded` | refund settled |
| 7 | `PaymentStatus::PendingRefund` | refund in progress |
| 8 | `PaymentStatus::RefundFailed` | refund failed |

### Signature algorithm

Both outgoing requests and incoming webhooks use the same scheme: join the non-empty fields, in documented order, as `Key=Value` pairs separated by commas; HMAC-SHA256 with the secret; Base64 of the raw digest; send in the `Authorization` header. The exact field lists live in `Samt\SkipCash\Signing\SignatureFields`.

### Exceptions

All extend `Samt\SkipCash\Exceptions\SkipCashException`:

`ConfigurationException` · `InvalidRequestException` · `ApiException` (`httpStatus`, `returnCode`, `errorCode`, `validationErrors`, `body`) · `InvalidSignatureException` · `WebhookVerificationException`.

---

## Development

```bash
composer install
composer check      # pint + phpstan + phpunit
```

## Security

Report vulnerabilities privately to security@samt.qa. Do not open public issues for security reports.

## License

MIT © [Samt](https://samt.qa)
