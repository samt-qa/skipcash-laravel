<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Samt\SkipCash\Enums\PaymentStatus;
use Samt\SkipCash\Exceptions\InvalidRequestException;
use Samt\SkipCash\Webhooks\WebhookPayload;

final class WebhookPayloadTest extends TestCase
{
    /** The example body from the "Webhooks" documentation page, verbatim. */
    private const DOCUMENTED_BODY = [
        'PaymentId' => 'c0168532-8e71-4623-b73a-c06db5cbd865',
        'Amount' => '1.00',
        'StatusId' => 2,
        'TransactionId' => '00bd9b86-0aa7-47ae-a568-0d7aa5d9fac6',
        'Custom1' => '0bcf8dd7-776c-4ee5-b883-81751b4c8ff3',
        'Custom2' => null,
        'Custom3' => null,
        'Custom4' => null,
        'Custom5' => null,
        'Custom6' => null,
        'Custom7' => null,
        'Custom8' => null,
        'Custom9' => null,
        'Custom10' => null,
        'VisaId' => '07082024100903646560',
        'TokenId' => 'NA',
        'CardType' => 'Debit Card',
        'CardNubmer' => null,
        'RecurringSubscriptionId' => '00000000-0000-0000-0000-000000000000',
    ];

    #[Test]
    public function it_parses_the_documented_example(): void
    {
        $payload = WebhookPayload::fromArray(self::DOCUMENTED_BODY);

        $this->assertSame('c0168532-8e71-4623-b73a-c06db5cbd865', $payload->paymentId);
        $this->assertSame('1.00', $payload->amount);
        $this->assertSame(PaymentStatus::Paid, $payload->status);
        $this->assertSame('00bd9b86-0aa7-47ae-a568-0d7aa5d9fac6', $payload->transactionId);
        $this->assertSame('0bcf8dd7-776c-4ee5-b883-81751b4c8ff3', $payload->custom(1));
        $this->assertNull($payload->custom(2));
        $this->assertSame('07082024100903646560', $payload->visaId);
        $this->assertNull($payload->tokenId, '"NA" means no token');
        $this->assertSame('Debit Card', $payload->cardType);
        $this->assertNull($payload->recurringSubscriptionId, 'nil UUID means not recurring');
        $this->assertTrue($payload->isPaid());
        $this->assertSame(self::DOCUMENTED_BODY, $payload->raw);
    }

    #[Test]
    public function it_accepts_both_spellings_of_the_card_number_field(): void
    {
        $this->assertSame('400000XXXXXX2503', WebhookPayload::fromArray(self::DOCUMENTED_BODY + ['CardNumber' => '400000XXXXXX2503'])->cardNumber);
        $this->assertSame('5200XXXX', WebhookPayload::fromArray(array_merge(self::DOCUMENTED_BODY, ['CardNubmer' => '5200XXXX']))->cardNumber);
    }

    #[Test]
    public function status_id_may_arrive_as_a_numeric_string(): void
    {
        $payload = WebhookPayload::fromArray(array_merge(self::DOCUMENTED_BODY, ['StatusId' => '4']));

        $this->assertSame(PaymentStatus::Failed, $payload->status);
    }

    #[Test]
    public function it_rejects_a_body_without_payment_id(): void
    {
        $this->expectException(InvalidRequestException::class);
        WebhookPayload::fromArray(['Amount' => '1.00', 'StatusId' => 2]);
    }

    #[Test]
    public function it_rejects_an_unknown_status(): void
    {
        $this->expectException(InvalidRequestException::class);
        WebhookPayload::fromArray(['PaymentId' => 'x', 'Amount' => '1.00', 'StatusId' => 99]);
    }
}
