<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Facades\SkipCash;
use Samt\SkipCash\Signing\SignatureFields;
use Samt\SkipCash\Signing\Signer;
use Samt\SkipCash\SkipCashServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const KEY_ID = '9f0c3d6e-1111-4a2b-8c3d-000000000001';

    protected const KEY_SECRET = 'test-key-secret';

    protected const WEBHOOK_KEY = 'test-webhook-key';

    protected const CLIENT_ID = 'client-id-test';

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SkipCashServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['SkipCash' => SkipCash::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('skipcash.environment', 'sandbox');
        $app['config']->set('skipcash.credentials', [
            'client_id' => self::CLIENT_ID,
            'key_id' => self::KEY_ID,
            'key_secret' => self::KEY_SECRET,
            'webhook_key' => self::WEBHOOK_KEY,
        ]);
        $app['config']->set('skipcash.webhooks.queue', false);
        $app['config']->set('skipcash.webhooks.middleware', []);
        $app['config']->set('skipcash.webhooks.verify_paid_via_api', false);
        $app['config']->set('skipcash.defaults.fallback_email_domain', 'test.local');
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function webhookBody(string $paymentId, PaymentStatus $status, array $overrides = []): array
    {
        return array_merge([
            'PaymentId' => $paymentId,
            'Amount' => '100.00',
            'StatusId' => $status->value,
            'TransactionId' => 'ORD-1',
            'Custom1' => null,
            'VisaId' => '07082024100903646560',
            'TokenId' => 'NA',
            'CardType' => 'Credit Card',
            'CardNubmer' => '400000XXXXXX2503',
            'RecurringSubscriptionId' => '00000000-0000-0000-0000-000000000000',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function webhookSignature(array $body, string $key = self::WEBHOOK_KEY): string
    {
        return (new Signer)->sign(SignatureFields::WEBHOOK, $body, $key);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function postWebhook(array $body, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('skipcash.webhook'), $body, [
            'Authorization' => $signature ?? $this->webhookSignature($body),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function apiEnvelope(array $resultObj, array $overrides = []): array
    {
        return array_merge([
            'resultObj' => $resultObj,
            'returnCode' => 200,
            'errorCode' => 0,
            'errorMessage' => null,
            'error' => null,
            'validationErrors' => null,
            'hasError' => false,
            'hasValidationError' => false,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function remotePayment(string $id, PaymentStatus $status, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'statusId' => $status->value,
            'created' => '2026-01-01T10:00:00Z',
            'payUrl' => 'https://skipcashtest.azurewebsites.net/pay/'.$id,
            'amount' => '100.00',
            'currency' => 'QAR',
            'transactionId' => 'ORD-1',
            'finishedDate' => $status->isOpen() ? null : '2026-01-01T10:05:00Z',
            'custom1' => null,
            'visaId' => $status->isOpen() ? null : '07082024100903646560',
            'tokenId' => '',
            'status' => $status->label(),
            'cardType' => $status->isOpen() ? null : 'Credit Card',
            'cardNumber' => null,
            'recurringSubscriptionId' => '00000000-0000-0000-0000-000000000000',
        ], $overrides);
    }
}
