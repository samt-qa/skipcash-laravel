<?php

declare(strict_types=1);

namespace Samt\SkipCash\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Samt\SkipCash\Exceptions\InvalidRequestException;
use Samt\SkipCash\Support\Amount;
use Samt\SkipCash\Support\Phone;
use Samt\SkipCash\Support\Uuid;

final class SupportTest extends TestCase
{
    #[Test]
    public function phone_normalisation_follows_the_documentation(): void
    {
        $this->assertSame('+97455123456', Phone::normalize('55123456'));
        $this->assertSame('+97455123456', Phone::normalize('+974 5512 3456'));
        $this->assertSame('+97455123456', Phone::normalize('0097455123456'), '"00" prefix becomes "+"');
        $this->assertSame('+966501234567', Phone::normalize('+966-50-123-4567'));
        $this->assertSame('+97455123456', Phone::normalize('97455123456'), 'local code without "+" is repaired');
    }

    #[Test]
    public function phone_rejects_foreign_numbers_without_country_code(): void
    {
        $this->expectException(InvalidRequestException::class);
        Phone::normalize('0501234567');
    }

    #[Test]
    public function amount_formatting_and_equality(): void
    {
        $this->assertSame('100.00', Amount::format(100));
        $this->assertSame('100.00', Amount::format('100'));
        $this->assertSame('0.50', Amount::format(0.5));
        $this->assertTrue(Amount::equals('100', '100.00'));
        $this->assertFalse(Amount::equals('100.01', '100.00'));
    }

    #[Test]
    public function uuid_v4_is_well_formed(): void
    {
        $uuid = Uuid::v4();

        $this->assertTrue(Uuid::isValid($uuid));
        $this->assertSame('4', $uuid[14]);
        $this->assertContains($uuid[19], ['8', '9', 'a', 'b']);
        $this->assertNotSame($uuid, Uuid::v4());
    }
}
