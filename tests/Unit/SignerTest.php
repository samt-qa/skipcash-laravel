<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Samt\SkipCash\Signing\SignatureFields;
use Samt\SkipCash\Signing\Signer;

/**
 * Field names and ordering below are copied verbatim from the SkipCash
 * developer documentation. Do not "normalise" them.
 */
final class SignerTest extends TestCase
{
    private Signer $signer;

    protected function setUp(): void
    {
        $this->signer = new Signer;
    }

    #[Test]
    public function it_builds_the_create_payment_payload_in_documented_order(): void
    {
        $payload = $this->signer->payload(SignatureFields::CREATE_PAYMENT, [
            'Custom1' => 'c1',
            'Email' => 'noor@example.com',
            'TransactionId' => 'ORD-1',
            'Phone' => '+97455123456',
            'Amount' => '100.00',
            'LastName' => 'Al Thani',
            'FirstName' => 'Noor',
            'KeyId' => 'key-1',
            'Uid' => 'uid-1',
        ]);

        $this->assertSame(
            'Uid=uid-1,KeyId=key-1,Amount=100.00,FirstName=Noor,LastName=Al Thani,Phone=+97455123456,Email=noor@example.com,TransactionId=ORD-1,Custom1=c1',
            $payload,
        );
    }

    #[Test]
    public function it_skips_empty_and_null_fields(): void
    {
        $payload = $this->signer->payload(SignatureFields::CREATE_PAYMENT, [
            'Uid' => 'u',
            'KeyId' => 'k',
            'Amount' => '1.00',
            'FirstName' => 'A',
            'LastName' => 'B',
            'Phone' => '+97450000000',
            'Email' => 'a@b.co',
            'Street' => '',
            'City' => null,
            'TransactionId' => '',
        ]);

        $this->assertSame('Uid=u,KeyId=k,Amount=1.00,FirstName=A,LastName=B,Phone=+97450000000,Email=a@b.co', $payload);
    }

    #[Test]
    public function body_only_fields_never_enter_the_signature(): void
    {
        $payload = $this->signer->payload(SignatureFields::CREATE_PAYMENT, [
            'Uid' => 'u', 'KeyId' => 'k', 'Amount' => '1.00',
            'FirstName' => 'A', 'LastName' => 'B', 'Phone' => '+97450000000', 'Email' => 'a@b.co',
            'Custom2' => 'x', 'TokenId' => 't', 'ReturnUrl' => 'https://r', 'WebhookUrl' => 'https://w',
            'Subject' => 's', 'Description' => 'd', 'ExpiryDate' => '2030-01-01', 'OnlyDebitCard' => true,
            'IsRecurring' => true, 'PlanName' => 'p',
        ]);

        $this->assertStringNotContainsString('Custom2', $payload);
        $this->assertStringNotContainsString('TokenId', $payload);
        $this->assertStringNotContainsString('ReturnUrl', $payload);
        $this->assertStringNotContainsString('WebhookUrl', $payload);
        $this->assertStringNotContainsString('IsRecurring', $payload);
        $this->assertStringNotContainsString('OnlyDebitCard', $payload);
    }

    #[Test]
    public function it_produces_base64_hmac_sha256_of_raw_bytes(): void
    {
        $data = ['Uid' => 'u', 'KeyId' => 'k', 'Amount' => '1.00', 'FirstName' => 'A', 'LastName' => 'B', 'Phone' => '+97450000000', 'Email' => 'a@b.co'];

        $expected = base64_encode(hash_hmac('sha256', 'Uid=u,KeyId=k,Amount=1.00,FirstName=A,LastName=B,Phone=+97450000000,Email=a@b.co', 'secret', true));

        $this->assertSame($expected, $this->signer->sign(SignatureFields::CREATE_PAYMENT, $data, 'secret'));
    }

    #[Test]
    public function webhook_payload_uses_wire_casing_and_documented_order(): void
    {
        // Exactly as SkipCash posts it: PascalCase keys.
        $body = [
            'PaymentId' => 'c0168532-8e71-4623-b73a-c06db5cbd865',
            'Amount' => '1.00',
            'StatusId' => 2,
            'TransactionId' => '00bd9b86-0aa7-47ae-a568-0d7aa5d9fac6',
            'Custom1' => '0bcf8dd7-776c-4ee5-b883-81751b4c8ff3',
            'Custom2' => null,
            'VisaId' => '07082024100903646560',
            'TokenId' => 'NA',
            'CardType' => 'Debit Card',
            'CardNubmer' => null,
            'RecurringSubscriptionId' => '00000000-0000-0000-0000-000000000000',
        ];

        $this->assertSame(
            'PaymentId=c0168532-8e71-4623-b73a-c06db5cbd865,Amount=1.00,StatusId=2,TransactionId=00bd9b86-0aa7-47ae-a568-0d7aa5d9fac6,Custom1=0bcf8dd7-776c-4ee5-b883-81751b4c8ff3,VisaId=07082024100903646560',
            $this->signer->payload(SignatureFields::WEBHOOK, $body),
        );
    }

    #[Test]
    public function webhook_without_transaction_id_and_custom1_matches_documented_minimal_form(): void
    {
        $body = ['PaymentId' => 'p', 'Amount' => '5.00', 'StatusId' => 2, 'TransactionId' => null, 'Custom1' => null, 'VisaId' => 'v'];

        $this->assertSame('PaymentId=p,Amount=5.00,StatusId=2,VisaId=v', $this->signer->payload(SignatureFields::WEBHOOK, $body));
    }

    #[Test]
    public function verify_is_constant_time_and_tolerates_surrounding_whitespace(): void
    {
        $data = ['PaymentId' => 'p', 'Amount' => '5.00', 'StatusId' => 2, 'VisaId' => 'v'];
        $signature = $this->signer->sign(SignatureFields::WEBHOOK, $data, 'whk');

        $this->assertTrue($this->signer->verify(SignatureFields::WEBHOOK, $data, 'whk', "  {$signature}\n"));
        $this->assertFalse($this->signer->verify(SignatureFields::WEBHOOK, $data, 'wrong', $signature));
        $this->assertFalse($this->signer->verify(SignatureFields::WEBHOOK, ['PaymentId' => 'p', 'Amount' => '6.00', 'StatusId' => 2, 'VisaId' => 'v'], 'whk', $signature));
    }

    #[Test]
    public function refund_cancel_and_update_card_signatures_use_only_documented_fields(): void
    {
        $this->assertSame('Id=pay,KeyId=key', $this->signer->payload(SignatureFields::REFUND, ['Id' => 'pay', 'KeyId' => 'key', 'Amount' => '10.00']));
        $this->assertSame('Id=sub,KeyId=key', $this->signer->payload(SignatureFields::CANCEL_SUBSCRIPTION, ['Id' => 'sub', 'KeyId' => 'key']));
        $this->assertSame(
            'KeyId=key,RecurringSubscriptionId=sub',
            $this->signer->payload(SignatureFields::UPDATE_CARD, ['Uid' => 'u', 'KeyId' => 'key', 'FirstName' => 'A', 'RecurringSubscriptionId' => 'sub']),
        );
    }
}
