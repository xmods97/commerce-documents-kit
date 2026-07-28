<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\Currency;

final class CurrencyTest extends TestCase
{
    public function testCreatesAndComparesCurrencies(): void
    {
        $first = Currency::fromCode('EUR');
        $second = Currency::fromCode('EUR');

        self::assertSame('EUR', $first->code());
        self::assertNotSame($first, $second);
        self::assertTrue($first->equals($second));
        self::assertFalse($first->equals(Currency::fromCode('GBP')));
    }

    /**
     * @dataProvider invalidCodes
     */
    public function testRejectsInvalidCode(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        Currency::fromCode($code);
    }

    public function invalidCodes(): array
    {
        return [
            'empty' => [''],
            'lowercase' => ['eur'],
            'short' => ['EU'],
            'long' => ['EURO'],
            'numeric' => ['123'],
            'whitespace' => [' EUR '],
        ];
    }
}
