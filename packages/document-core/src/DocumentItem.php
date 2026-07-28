<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

/**
 * Immutable commercial document line with snapshotted price and tax rate.
 */
final class DocumentItem
{
    /** @var string */
    private $description;

    /** @var Quantity */
    private $quantity;

    /** @var string */
    private $unit;

    /** @var Money */
    private $unitNet;

    /** @var TaxRate */
    private $taxRate;
    /** @var Money|null */
    private $lineNet;
    /** @var Money|null */
    private $lineTax;

    private function __construct(
        string $description,
        Quantity $quantity,
        string $unit,
        Money $unitNet,
        TaxRate $taxRate,
        ?Money $lineNet = null,
        ?Money $lineTax = null
    ) {
        $description = trim($description);
        $unit = trim($unit);

        if ($description === '') {
            throw new InvalidArgumentException('Document item description cannot be empty.');
        }

        if ($unit === '') {
            throw new InvalidArgumentException('Document item unit cannot be empty.');
        }
        foreach ([$lineNet, $lineTax] as $amount) {
            if ($amount !== null && !$amount->currency()->equals($unitNet->currency())) {
                throw new InvalidArgumentException('Snapshot line amounts must use the item currency.');
            }
        }

        $this->description = $description;
        $this->quantity = $quantity;
        $this->unit = $unit;
        $this->unitNet = $unitNet;
        $this->taxRate = $taxRate;
        $this->lineNet = $lineNet;
        $this->lineTax = $lineTax;
    }

    public static function create(
        string $description,
        Quantity $quantity,
        string $unit,
        Money $unitNet,
        TaxRate $taxRate
    ): self {
        return new self($description, $quantity, $unit, $unitNet, $taxRate);
    }

    public static function fromSnapshotAmounts(
        string $description,
        Quantity $quantity,
        string $unit,
        Money $unitNet,
        TaxRate $taxRate,
        Money $lineNet,
        Money $lineTax
    ): self {
        return new self(
            $description,
            $quantity,
            $unit,
            $unitNet,
            $taxRate,
            $lineNet,
            $lineTax
        );
    }

    public function description(): string
    {
        return $this->description;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    public function unit(): string
    {
        return $this->unit;
    }

    public function unitNet(): Money
    {
        return $this->unitNet;
    }

    public function taxRate(): TaxRate
    {
        return $this->taxRate;
    }

    public function net(): Money
    {
        return $this->lineNet ?? $this->quantity->multiplyMoney($this->unitNet);
    }

    public function tax(): Money
    {
        return $this->lineTax ?? $this->taxRate->calculateTax($this->net());
    }

    public function gross(): Money
    {
        return $this->net()->add($this->tax());
    }
}
