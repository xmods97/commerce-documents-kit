<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use InvalidArgumentException;
use RuntimeException;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DecimalAmountParser;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\TaxRate;

final class NativeOrderAdapter
{
    /** @var Party */
    private $seller;
    /** @var Language */
    private $defaultLanguage;
    /** @var int */
    private $currencyExponent;

    public function __construct(
        Party $seller,
        Language $defaultLanguage,
        int $currencyExponent
    ) {
        if ($currencyExponent < 0 || $currencyExponent > 6) {
            throw new InvalidArgumentException('Currency exponent is invalid.');
        }
        $this->seller = $seller;
        $this->defaultLanguage = $defaultLanguage;
        $this->currencyExponent = $currencyExponent;
    }

    /**
     * @param object $order WC_Order-compatible object
     */
    public function map($order): OrderData
    {
        foreach (['get_id', 'get_status', 'get_currency', 'get_items', 'get_date_created'] as $method) {
            if (!is_object($order) || !method_exists($order, $method)) {
                throw new RuntimeException('WooCommerce order object is incompatible.');
            }
        }

        $currency = Currency::fromCode((string) $order->get_currency());
        $languageTag = method_exists($order, 'get_meta')
            ? (string) $order->get_meta('_commerce_documents_language', true)
            : '';
        $language = $languageTag !== '' ? Language::fromTag($languageTag) : $this->defaultLanguage;
        $items = [];
        foreach (array_merge(
            array_values((array) $order->get_items('line_item')),
            array_values((array) $order->get_items('fee'))
        ) as $item) {
            $items[] = $this->mapItem($item, $currency);
        }

        if (method_exists($order, 'get_shipping_total')) {
            $shippingNet = $this->amount((string) $order->get_shipping_total(), $currency);
            $shippingTax = $this->amount((string) $order->get_shipping_tax(), $currency);
            if ($shippingNet->minorUnits() !== 0 || $shippingTax->minorUnits() !== 0) {
                $items[] = DocumentItem::fromSnapshotAmounts(
                    'Shipping',
                    Quantity::one(),
                    'service',
                    $shippingNet,
                    $this->effectiveRate($shippingNet, $shippingTax),
                    $shippingNet,
                    $shippingTax
                );
            }
        }

        return new OrderData(
            (string) $order->get_id(),
            (string) $order->get_status(),
            $this->date($order->get_date_created()),
            method_exists($order, 'get_date_paid') ? $this->date($order->get_date_paid(), true) : '',
            $currency,
            $language,
            $this->seller,
            $this->buyer($order),
            $items,
            method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : ''
        );
    }

    private function mapItem($item, Currency $currency): DocumentItem
    {
        foreach (['get_name', 'get_quantity', 'get_total', 'get_total_tax'] as $method) {
            if (!is_object($item) || !method_exists($item, $method)) {
                throw new RuntimeException('WooCommerce order item is incompatible.');
            }
        }
        $quantityValue = (string) $item->get_quantity();
        if (!preg_match('/^-?\d+$/D', $quantityValue)) {
            throw new RuntimeException('WooCommerce item quantity must be an integer in this adapter.');
        }
        $quantity = Quantity::fromScaledUnits((int) $quantityValue, 0);
        $lineNet = $this->amount((string) $item->get_total(), $currency);
        $lineTax = $this->amount((string) $item->get_total_tax(), $currency);
        $unitNet = $this->divideByQuantity($lineNet, $quantity->scaledUnits());
        $unit = method_exists($item, 'get_meta')
            ? trim((string) $item->get_meta('_commerce_documents_unit', true))
            : '';

        return DocumentItem::fromSnapshotAmounts(
            (string) $item->get_name(),
            $quantity,
            $unit !== '' ? $unit : 'unit',
            $unitNet,
            $this->taxRateForItem($item, $lineNet, $lineTax),
            $lineNet,
            $lineTax
        );
    }

    private function buyer($order): Party
    {
        $name = trim((string) $order->get_billing_company());
        if ($name === '') {
            $name = trim(
                (string) $order->get_billing_first_name()
                . ' '
                . (string) $order->get_billing_last_name()
            );
        }
        $taxId = method_exists($order, 'get_meta')
            ? (string) $order->get_meta('_billing_vat_id', true)
            : '';

        return Party::create(
            $name,
            $taxId,
            (string) $order->get_billing_email(),
            Address::create(
                (string) $order->get_billing_address_1(),
                (string) $order->get_billing_address_2(),
                (string) $order->get_billing_postcode(),
                (string) $order->get_billing_city(),
                (string) $order->get_billing_state(),
                strtoupper((string) $order->get_billing_country())
            )
        );
    }

    private function amount(string $decimal, Currency $currency): Money
    {
        return Money::fromMinorUnits(
            DecimalAmountParser::minorUnits($decimal, $this->currencyExponent),
            $currency
        );
    }

    private function divideByQuantity(Money $line, int $quantity): Money
    {
        if ($quantity === 0) {
            return Money::zero($line->currency());
        }
        $value = $line->minorUnits();
        $quotient = intdiv($value, $quantity);
        $remainder = $value % $quantity;
        if (abs($remainder) * 2 >= abs($quantity)) {
            $quotient += (($value < 0) xor ($quantity < 0)) ? -1 : 1;
        }
        return Money::fromMinorUnits($quotient, $line->currency());
    }

    private function effectiveRate(Money $net, Money $tax): TaxRate
    {
        if ($net->minorUnits() === PHP_INT_MIN || $tax->minorUnits() === PHP_INT_MIN) {
            throw new RuntimeException('Order amount is outside the supported tax calculation range.');
        }
        $netUnits = abs($net->minorUnits());
        $taxUnits = abs($tax->minorUnits());
        if ($netUnits === 0 || $taxUnits === 0) {
            return TaxRate::zero();
        }
        if ($taxUnits > $netUnits) {
            throw new RuntimeException('Effective tax rate above 100% is unsupported.');
        }
        if ($taxUnits > intdiv(PHP_INT_MAX - intdiv($netUnits, 2), 1000000)) {
            throw new RuntimeException('Order amount is outside the supported tax calculation range.');
        }
        $ppm = intdiv($taxUnits * 1000000 + intdiv($netUnits, 2), $netUnits);
        return TaxRate::fromPartsPerMillion($ppm);
    }

    /**
     * WooCommerce stores the authoritative tax rate separately from the line
     * totals. Prefer that rate: line tax is rounded to currency precision, so
     * deriving a percentage from line_tax / line_total turns a real 23% rate
     * into values such as 22.98% for small lines.
     */
    private function taxRateForItem($item, Money $net, Money $tax): TaxRate
    {
        if (method_exists($item, 'get_taxes') && class_exists('WC_Tax')) {
            $taxes = (array) $item->get_taxes();
            $totals = (array) ($taxes['total'] ?? []);
            $rateIds = array_keys($totals);
            if (count($rateIds) === 1) {
                $rates = \WC_Tax::get_rates((string) $rateIds[0]);
                if (is_array($rates) && count($rates) === 1) {
                    $rate = self::taxRateFromDecimal((string) ($rates[0]['rate'] ?? ''));
                    if ($rate instanceof TaxRate) {
                        return $rate;
                    }
                }
            }
        }

        // Framework-free tests and unusual legacy orders may not expose a tax
        // rate ID. Keep the old bounded fallback for those cases.
        return $this->effectiveRate($net, $tax);
    }

    private static function taxRateFromDecimal(string $value): ?TaxRate
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^\d{1,3}(?:\.\d{1,6})?$/D', $value) !== 1) {
            return null;
        }
        $parts = explode('.', $value, 2);
        $whole = (int) $parts[0];
        // WooCommerce expresses the rate as a percentage (23.0000), while
        // the document model stores the equivalent ratio in parts per million
        // (230000). Keep the conversion integer-based and deterministic.
        $fraction = str_pad((string) ($parts[1] ?? ''), 6, '0');
        $percentScaled = $whole * 1000000 + (int) substr($fraction, 0, 6);
        $ppm = intdiv($percentScaled + 50, 100);
        return $ppm <= 1000000 ? TaxRate::fromPartsPerMillion($ppm) : null;
    }

    private function date($date, bool $optional = false): string
    {
        if ($date === null && $optional) {
            return '';
        }
        if (!is_object($date) || !method_exists($date, 'date')) {
            throw new RuntimeException('WooCommerce order date is invalid.');
        }
        return (string) $date->date(DATE_ATOM);
    }
}
