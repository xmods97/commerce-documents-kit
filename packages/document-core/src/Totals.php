<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

final class Totals
{
    /** @var Money */
    private $net;

    /** @var Money */
    private $tax;

    /** @var Money */
    private $gross;

    /** @var TaxBreakdown */
    private $taxBreakdown;

    private function __construct(
        Money $net,
        Money $tax,
        Money $gross,
        TaxBreakdown $taxBreakdown
    ) {
        $this->net = $net;
        $this->tax = $tax;
        $this->gross = $gross;
        $this->taxBreakdown = $taxBreakdown;
    }

    /**
     * @param DocumentItem[] $items
     */
    public static function fromItems(array $items, Currency $currency): self
    {
        $net = Money::zero($currency);
        $tax = Money::zero($currency);

        foreach ($items as $item) {
            $net = $net->add($item->net());
            $tax = $tax->add($item->tax());
        }

        return new self(
            $net,
            $tax,
            $net->add($tax),
            TaxBreakdown::fromItems($items, $currency)
        );
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function tax(): Money
    {
        return $this->tax;
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    public function taxBreakdown(): TaxBreakdown
    {
        return $this->taxBreakdown;
    }
}
