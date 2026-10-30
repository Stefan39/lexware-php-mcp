<?php

declare(strict_types=1);

namespace Jacomeit\LexwarePhpMcp\Tests\Lexware;

use Jacomeit\LexwarePhpMcp\Lexware\VoucherFields;
use PHPUnit\Framework\TestCase;

final class VoucherFieldsTest extends TestCase
{
    public function testStringReturnsValueOrDefault(): void
    {
        self::assertSame('ACME', VoucherFields::string(['name' => 'ACME'], 'name'));
        self::assertSame('', VoucherFields::string([], 'name'));
        self::assertSame('EUR', VoucherFields::string(['currency' => 42], 'currency', 'EUR'));
    }

    public function testAmountRoundsToTwoDecimals(): void
    {
        self::assertSame(1190.0, VoucherFields::amount(['a' => 1190], 'a'));
        self::assertSame(10.13, VoucherFields::amount(['a' => 10.125], 'a'));
        self::assertSame(0.0, VoucherFields::amount(['a' => '12.5'], 'a'));
        self::assertSame(0.0, VoucherFields::amount([], 'a'));
    }

    public function testDateKeepsOnlyCalendarDateInUtc(): void
    {
        $date = VoucherFields::date(['d' => '2026-09-30T00:00:00.000+02:00'], 'd');

        self::assertNotNull($date);
        self::assertSame('2026-09-30 00:00:00', $date->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testDateIsNullForMissingOrMalformedValue(): void
    {
        self::assertNull(VoucherFields::date([], 'd'));
        self::assertNull(VoucherFields::date(['d' => 'tomorrow'], 'd'));
        self::assertNull(VoucherFields::date(['d' => 20260930], 'd'));
    }
}
