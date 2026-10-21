<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Responses\Payment;

final class PaymentResponseTest extends TestCase
{
    /** `resultObj` from the "Get Transaction Details" documentation page. */
    private const DOCUMENTED_RESULT = [
        'id' => 'd024a414-772e-49d1-a64b-6b176311f97b',
        'statusId' => 2,
        'created' => '2024-10-11T23:44:16Z',
        'payUrl' => 'https://skipcashtest.azurewebsites.net/pay/d024a414-772e-49d1-a64b-6b176311f97b',
        'amount' => '1.00',
        'firstPaymentAmount' => null,
        'currency' => 'QAR',
        'transactionId' => 'fe3896dc-c32d-4899-a11f-6ba6f8539776',
        'finishedDate' => '2024-10-11T23:44:41Z',
        'custom1' => '0ac09372-98cd-46a3-83b7-43d5381afcbd',
        'custom2' => null,
        'visaId' => '7286794806446167704010',
        'tokenId' => '',
        'status' => 'paid',
        'cardType' => 'Credit Card',
        'cardNumber' => '400000XXXXXX2503',
        'recurringSubscriptionId' => '00000000-0000-0000-0000-000000000000',
    ];

    #[Test]
    public function it_maps_the_documented_result_object(): void
    {
        $payment = Payment::fromArray(self::DOCUMENTED_RESULT);

        $this->assertSame('d024a414-772e-49d1-a64b-6b176311f97b', $payment->id);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertTrue($payment->isPaid());
        $this->assertSame('1.00', $payment->amount);
        $this->assertSame('QAR', $payment->currency);
        $this->assertSame('2024-10-11T23:44:16+00:00', $payment->createdAt?->format(DATE_ATOM));
        $this->assertSame('2024-10-11T23:44:41+00:00', $payment->finishedAt?->format(DATE_ATOM));
        $this->assertSame('7286794806446167704010', $payment->visaId);
        $this->assertNull($payment->tokenId);
        $this->assertFalse($payment->hasToken());
        $this->assertSame('Credit Card', $payment->cardType);
        $this->assertSame('400000XXXXXX2503', $payment->cardNumber);
        $this->assertNull($payment->recurringSubscriptionId);
        $this->assertFalse($payment->isRecurring());
        $this->assertSame('0ac09372-98cd-46a3-83b7-43d5381afcbd', $payment->custom(1));
    }

    #[Test]
    public function it_exposes_refund_status_from_the_original_payment(): void
    {
        $payment = Payment::fromArray(self::DOCUMENTED_RESULT + ['refundId' => 'r-1', 'refundStatusId' => 7]);

        $this->assertSame('r-1', $payment->refundId);
        $this->assertSame(PaymentStatus::PendingRefund, $payment->refundStatus);
    }

    #[Test]
    public function it_tolerates_a_minimal_create_response(): void
    {
        $payment = Payment::fromArray(['id' => 'x', 'statusId' => 0, 'payUrl' => 'https://pay/x', 'amount' => '5.00']);

        $this->assertSame(PaymentStatus::New, $payment->status);
        $this->assertNull($payment->finishedAt);
        $this->assertNull($payment->transactionId);
    }
}
