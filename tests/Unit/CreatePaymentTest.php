<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Samt\SkipCash\Enums\Interval;
use Samt\SkipCash\Exceptions\InvalidRequestException;
use Samt\SkipCash\Requests\CreatePayment;
use Samt\SkipCash\Requests\RecurringPlan;
use Samt\SkipCash\Support\Uuid;

final class CreatePaymentTest extends TestCase
{
    #[Test]
    public function it_builds_a_minimal_body_with_generated_uid(): void
    {
        $request = CreatePayment::make(amount: 250, firstName: 'Noor', lastName: 'Al Thani', phone: '55123456', email: 'noor@example.com');

        $body = $request->toArray('key-1');

        $this->assertTrue(Uuid::isValid($body['Uid']));
        $this->assertSame('key-1', $body['KeyId']);
        $this->assertSame('250.00', $body['Amount']);
        $this->assertSame('+97455123456', $body['Phone']);
        $this->assertArrayNotHasKey('Street', $body);
        $this->assertArrayNotHasKey('TransactionId', $body);
        $this->assertSame($body['Uid'], $request->uid(), 'uid must be stable across calls');
    }

    #[Test]
    public function it_formats_amounts_as_two_decimal_strings(): void
    {
        $this->assertSame('99.50', CreatePayment::make('99.5', 'A', 'B', '55123456', 'a@b.co')->amount);
        $this->assertSame('1000.00', CreatePayment::make(1000, 'A', 'B', '55123456', 'a@b.co')->amount);
        $this->assertSame('0.10', CreatePayment::make(0.1, 'A', 'B', '55123456', 'a@b.co')->amount);
        $this->assertSame('12.35', CreatePayment::make(12.345, 'A', 'B', '55123456', 'a@b.co')->amount);
    }

    #[Test]
    public function it_derives_a_fallback_email_from_the_phone(): void
    {
        $request = CreatePayment::make(10, 'A', 'B', '+97455123456', null, fallbackEmailDomain: 'shop.qa');

        $this->assertSame('97455123456@shop.qa', $request->email);
    }

    #[Test]
    public function custom1_is_in_the_body_and_signature_but_custom2_is_body_only(): void
    {
        $body = CreatePayment::make(10, 'A', 'B', '55123456', 'a@b.co')
            ->withCustom(1, 'one')
            ->withCustom(2, 'two')
            ->toArray('k');

        $this->assertSame('one', $body['Custom1']);
        $this->assertSame('two', $body['Custom2']);
    }

    #[Test]
    public function fluent_modifiers_are_immutable(): void
    {
        $base = CreatePayment::make(10, 'A', 'B', '55123456', 'a@b.co');
        $withToken = $base->withToken('tok');

        $this->assertArrayNotHasKey('TokenId', $base->toArray('k'));
        $this->assertSame('tok', $withToken->toArray('k')['TokenId']);
    }

    #[Test]
    public function recurring_plan_fields_are_appended_to_the_body(): void
    {
        $plan = RecurringPlan::make('Gold', Interval::Month, '2026-01-01', '2026-12-31', firstPaymentAmount: 1);

        $body = CreatePayment::make(50, 'A', 'B', '55123456', 'a@b.co')->recurring($plan)->toArray('k');

        $this->assertTrue($body['IsRecurring']);
        $this->assertSame('Gold', $body['PlanName']);
        $this->assertSame('1', $body['Frequency']);
        $this->assertSame('month', $body['Interval']);
        $this->assertSame('2026-01-01', $body['StartDate']);
        $this->assertSame('2026-12-31', $body['EndDate']);
        $this->assertSame('3', $body['AllowedFailedAttempts']);
        $this->assertSame('1.00', $body['FirstPaymentAmount']);
    }

    #[Test]
    public function debit_only_flag_is_sent_as_a_boolean(): void
    {
        $body = CreatePayment::make(10, 'A', 'B', '55123456', 'a@b.co')->debitCardOnly()->toArray('k');

        $this->assertTrue($body['OnlyDebitCard']);
    }

    #[Test]
    public function names_with_arabic_letters_are_accepted(): void
    {
        $request = CreatePayment::make(10, 'محمد', 'العبدالله', '55123456', 'a@b.co');

        $this->assertSame('محمد', $request->firstName);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalidInputs')]
    public function it_rejects_invalid_input_locally(array $overrides, string $expectedField): void
    {
        $args = array_merge([
            'amount' => 10, 'firstName' => 'A', 'lastName' => 'B', 'phone' => '55123456', 'email' => 'a@b.co',
        ], $overrides);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage("[{$expectedField}]");

        CreatePayment::make(...$args);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'zero amount' => [['amount' => 0], 'Amount'];
        yield 'negative amount' => [['amount' => -5], 'Amount'];
        yield 'non numeric amount' => [['amount' => 'abc'], 'Amount'];
        yield 'empty first name' => [['firstName' => '  '], 'FirstName'];
        yield 'special chars in name' => [['firstName' => 'A#B'], 'FirstName'];
        yield 'name too long' => [['lastName' => str_repeat('a', 61)], 'LastName'];
        yield 'foreign phone without code' => [['phone' => '0501234567'], 'Phone'];
        yield 'phone with letters' => [['phone' => '55AB3456'], 'Phone'];
        yield 'invalid email' => [['email' => 'not-an-email'], 'Email'];
        yield 'transaction id with hash' => [['transactionId' => 'ORD#1'], 'TransactionId'];
        yield 'transaction id too long' => [['transactionId' => str_repeat('1', 41)], 'TransactionId'];
        yield 'state too long' => [['state' => 'DOH'], 'State'];
        yield 'address total over 50' => [['street' => str_repeat('s', 30), 'city' => str_repeat('c', 25)], 'Address'];
    }

    #[Test]
    public function it_rejects_custom_slot_out_of_range_and_oversized_values(): void
    {
        $request = CreatePayment::make(10, 'A', 'B', '55123456', 'a@b.co');

        $this->expectException(InvalidRequestException::class);
        $request->withCustom(11, 'x');
    }

    #[Test]
    public function it_rejects_relative_urls(): void
    {
        $this->expectException(InvalidRequestException::class);
        CreatePayment::make(10, 'A', 'B', '55123456', 'a@b.co')->withReturnUrl('/thanks');
    }
}
