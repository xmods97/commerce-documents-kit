<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class TaxBreakdown
{
    /** @var array<int, Money> */
    private $amountsByRate;

    /**
     * @param array<int, Money> $amountsByRate
     */
    private function __construct(array $amountsByRate)
    {
        ksort($amountsByRate, SORT_NUMERIC);
        $this->amountsByRate = $amountsByRate;
    }

    /**
     * @param DocumentItem[] $items
     */
    public static function fromItems(array $items, Currency $currency): self
    {
        $amounts = [];

        foreach ($items as $item) {
            if (!$item instanceof DocumentItem) {
                throw new InvalidArgumentException('Tax breakdown accepts DocumentItem values only.');
            }

            if (!$item->unitNet()->currency()->equals($currency)) {
                throw new InvalidArgumentException('All document items must use the document currency.');
            }

            $rate = $item->taxRate()->partsPerMillion();
            $amounts[$rate] = isset($amounts[$rate])
                ? $amounts[$rate]->add($item->tax())
                : $item->tax();
        }

        return new self($amounts);
    }

    /**
     * @return array<int, Money>
     */
    public function amountsByPartsPerMillion(): array
    {
        return $this->amountsByRate;
    }

    public function amountFor(TaxRate $rate, Currency $currency): Money
    {
        return $this->amountsByRate[$rate->partsPerMillion()] ?? Money::zero($currency);
    }
}
