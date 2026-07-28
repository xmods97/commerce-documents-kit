<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\DecimalAmountParser;

final class DecimalAmountParserTest extends TestCase
{
    /**
     * @dataProvider amounts
     */
    public function testParsesWithoutFloatingPoint(string $amount, int $exponent, int $expected): void
    {
        self::assertSame($expected, DecimalAmountParser::minorUnits($amount, $exponent));
    }

    public function amounts(): array
    {
        return [
            'whole' => ['12', 2, 1200],
            'fraction' => ['12.34', 2, 1234],
            'padding' => ['12.3', 2, 1230],
            'positive half' => ['12.345', 2, 1235],
            'negative half' => ['-12.345', 2, -1235],
            'zero exponent' => ['2.5', 0, 3],
            'minimum' => [(string) PHP_INT_MIN, 0, PHP_INT_MIN],
            'maximum' => [(string) PHP_INT_MAX, 0, PHP_INT_MAX],
        ];
    }

    public function testRejectsInvalidDecimal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalAmountParser::minorUnits('1,23', 2);
    }

    public function testDetectsRoundedOverflow(): void
    {
        $this->expectException(OverflowException::class);
        DecimalAmountParser::minorUnits((string) PHP_INT_MAX . '.5', 0);
    }
}
