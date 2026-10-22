<?php

declare(strict_types=1);

namespace Samt\SkipCash\Facades;

use Illuminate\Support\Facades\Facade;
use Samt\SkipCash\Testing\SkipCashFake;

/**
 * @method static \Samt\SkipCash\Responses\Payment createPayment(\Samt\SkipCash\Requests\CreatePayment $request, ?\Illuminate\Database\Eloquent\Model $payable = null)
 * @method static \Samt\SkipCash\Responses\Payment payment(string $paymentId)
 * @method static \Samt\SkipCash\Responses\Payment sync(string $paymentId)
 * @method static \Samt\SkipCash\Payments\Transition syncRecord(\Samt\SkipCash\Models\SkipCashPayment $record)
 * @method static \Samt\SkipCash\Responses\Refund refund(string $paymentId, int|float|string|null $amount = null)
 * @method static \Samt\SkipCash\Responses\SubscriptionCancellation cancelSubscription(string $subscriptionId)
 * @method static \Samt\SkipCash\Responses\Payment updateSubscriptionCard(string $subscriptionId, string $firstName, string $lastName, string $phone, string $email)
 * @method static list<\Samt\SkipCash\Responses\SavedCard> savedCards(string|list<string> $tokenIds)
 * @method static \Samt\SkipCash\Enums\Environment environment()
 * @method static \Samt\SkipCash\Client\SkipCashClient client()
 *
 * @see \Samt\SkipCash\SkipCash
 */
final class SkipCash extends Facade
{
    /**
     * Replace the gateway with an in-memory fake for tests.
     *
     * Also disables API confirmation of "paid" webhooks, so posting a signed
     * webhook in a test never reaches the real SkipCash API.
     */
    public static function fake(): SkipCashFake
    {
        self::$app->make('config')->set('skipcash.webhooks.verify_paid_via_api', false);

        $fake = self::$app->make(SkipCashFake::class);

        self::$app->instance(\Samt\SkipCash\SkipCash::class, $fake);
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Samt\SkipCash\SkipCash::class;
    }
}
