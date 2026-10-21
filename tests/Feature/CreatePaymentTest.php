<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Exceptions\ApiException;
use Samt\SkipCash\Facades\SkipCash;
use Samt\SkipCash\Models\SkipCashPayment;
use Samt\SkipCash\Requests\CreatePayment;
use Samt\SkipCash\Signing\SignatureFields;
use Samt\SkipCash\Signing\Signer;
use Samt\SkipCash\Tests\TestCase;

final class CreatePaymentTest extends TestCase
{
    #[Test]
    public function it_posts_a_signed_request_to_the_sandbox_and_records_the_payment(): void
    {
        Http::fake([
            'skipcashtest.azurewebsites.net/api/v1/payments' => Http::response(
                $this->apiEnvelope($this->remotePayment('pay-1', PaymentStatus::New)),
            ),
        ]);

        $request = CreatePayment::make(100, 'Noor', 'Al Thani', '55123456', 'noor@example.com', transactionId: 'ORD-1')
            ->withUid('11111111-2222-4333-8444-555555555555');

        $payment = SkipCash::createPayment($request);

        $this->assertSame('pay-1', $payment->id);
        $this->assertSame(PaymentStatus::New, $payment->status);
        $this->assertStringStartsWith('https://skipcashtest.azurewebsites.net/pay/', (string) $payment->payUrl);

        Http::assertSent(function (Request $sent): bool {
            $body = $sent->data();
            $expected = (new Signer)->sign(SignatureFields::CREATE_PAYMENT, $body, self::KEY_SECRET);

            return $sent->url() === 'https://skipcashtest.azurewebsites.net/api/v1/payments'
                && $sent->hasHeader('Authorization', $expected)
                && $body['Uid'] === '11111111-2222-4333-8444-555555555555'
                && $body['KeyId'] === self::KEY_ID
                && $body['Amount'] === '100.00'
                && $body['Phone'] === '+97455123456'
                && $body['TransactionId'] === 'ORD-1'
                && isset($body['WebhookUrl']);
        });

        $this->assertDatabaseHas('skipcash_payments', [
            'payment_id' => 'pay-1',
            'transaction_id' => 'ORD-1',
            'status' => PaymentStatus::New->value,
            'amount' => '100.00',
            'environment' => 'sandbox',
        ]);
    }

    #[Test]
    public function fallback_email_domain_comes_from_config(): void
    {
        Http::fake(['*' => Http::response($this->apiEnvelope($this->remotePayment('pay-e', PaymentStatus::New)))]);

        // TestCase sets skipcash.defaults.fallback_email_domain = "test.local".
        SkipCash::createPayment(CreatePayment::make(10, 'A', 'B', '55123456'));

        Http::assertSent(fn (Request $r) => $r->data()['Email'] === '97455123456@test.local');
    }

    #[Test]
    public function partial_config_overrides_keep_the_remaining_package_defaults(): void
    {
        // TestCase overrides only some skipcash.webhooks.* keys.
        $this->assertSame('skipcash/webhook', config('skipcash.webhooks.path'));
        $this->assertTrue(config('skipcash.webhooks.register_route'));
        $this->assertFalse(config('skipcash.webhooks.queue'));
        $this->assertSame('+974', config('skipcash.defaults.local_country_code'));
    }

    #[Test]
    public function it_uses_the_production_host_when_configured(): void
    {
        config()->set('skipcash.environment', 'production');
        Http::fake(['api.skipcash.app/*' => Http::response($this->apiEnvelope($this->remotePayment('pay-2', PaymentStatus::New)))]);

        SkipCash::createPayment(CreatePayment::make(10, 'A', 'B', '55123456', 'a@b.co'));

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.skipcash.app/'));
    }

    #[Test]
    public function api_errors_become_typed_exceptions_with_details(): void
    {
        Http::fake(['*' => Http::response([
            'resultObj' => null,
            'returnCode' => 400,
            'errorCode' => 1001,
            'errorMessage' => 'Missing or invalid key',
            'validationErrors' => ['Phone' => ['Invalid']],
            'hasError' => true,
            'hasValidationError' => true,
        ], 400)]);

        try {
            SkipCash::createPayment(CreatePayment::make(10, 'A', 'B', '55123456', 'a@b.co'));
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame(1001, $e->errorCode);
            $this->assertTrue($e->isValidationError());
            $this->assertStringContainsString('Missing or invalid key', $e->getMessage());
        }

        $this->assertSame(0, SkipCashPayment::query()->count(), 'nothing is recorded on failure');
    }

    #[Test]
    public function transport_failures_are_wrapped(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not reach SkipCash');

        SkipCash::createPayment(CreatePayment::make(10, 'A', 'B', '55123456', 'a@b.co'));
    }

    #[Test]
    public function get_payment_uses_the_client_id_as_authorization(): void
    {
        Http::fake(['*/api/v1/payments/pay-9' => Http::response($this->apiEnvelope($this->remotePayment('pay-9', PaymentStatus::Paid)))]);

        $payment = SkipCash::payment('pay-9');

        $this->assertTrue($payment->isPaid());
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->hasHeader('Authorization', self::CLIENT_ID));
    }

    #[Test]
    public function refund_signs_only_id_and_key_id(): void
    {
        Http::fake(['*/api/v1/payments/refund' => Http::response($this->apiEnvelope(['refundId' => 'ref-1']))]);

        $refund = SkipCash::refund('pay-1', 25);

        $this->assertSame('ref-1', $refund->refundId);
        $this->assertSame('25.00', $refund->amount);

        Http::assertSent(function (Request $r): bool {
            $expected = (new Signer)->sign(['Id', 'KeyId'], $r->data(), self::KEY_SECRET);

            return $r->data()['Amount'] === '25.00' && $r->hasHeader('Authorization', $expected);
        });
    }

    #[Test]
    public function sync_applies_the_remote_status_and_fires_events(): void
    {
        Http::fake([
            '*/api/v1/payments' => Http::response($this->apiEnvelope($this->remotePayment('pay-3', PaymentStatus::New))),
            '*/api/v1/payments/pay-3' => Http::response($this->apiEnvelope($this->remotePayment('pay-3', PaymentStatus::Paid))),
        ]);

        \Illuminate\Support\Facades\Event::fake([\Samt\SkipCash\Events\PaymentPaid::class]);

        SkipCash::createPayment(CreatePayment::make(100, 'A', 'B', '55123456', 'a@b.co', transactionId: 'ORD-1'));
        SkipCash::sync('pay-3');

        $this->assertDatabaseHas('skipcash_payments', ['payment_id' => 'pay-3', 'status' => PaymentStatus::Paid->value]);
        \Illuminate\Support\Facades\Event::assertDispatched(\Samt\SkipCash\Events\PaymentPaid::class, fn ($e) => $e->payment->payment_id === 'pay-3' && $e->webhook === null);
    }
}
