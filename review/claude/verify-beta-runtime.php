<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/tools/runtime-autoload.php';

if (!class_exists('WC_Tax')) {
    class WC_Tax
    {
        public static function get_rates(string $taxRateId): array
        {
            return [['rate' => '23.0000']];
        }
    }
}

use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\Rendering\BasicPdfRenderer;
use Xmods\CommerceDocuments\Rendering\HtmlRenderer;
use Xmods\CommerceDocuments\Rendering\TemplateCatalog;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\WooCommerce\CodOrderPolicy;
use Xmods\CommerceDocuments\WooCommerce\NativeOrderAdapter;
use Xmods\CommerceDocuments\WooCommerce\OrderData;
use Xmods\CommerceDocuments\WooCommerce\OrderMapper;

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "PASS: {$name}" . ($detail === '' ? '' : " — {$detail}") . PHP_EOL;
        return;
    }
    $fail++;
    echo "FAIL: {$name}" . ($detail === '' ? '' : " — {$detail}") . PHP_EOL;
};

$currency = Currency::fromCode('PLN');
$address = Address::create('Testowa 1', '', '00-001', 'Warszawa', '', 'PL');
$seller = Party::create('GEWARD', '123', 'shop@example.invalid', $address);
$buyer = Party::create('Buyer', '', 'buyer@example.invalid', $address);
$item = DocumentItem::create(
    'Granite item',
    Quantity::one(),
    'szt.',
    Money::fromMinorUnits(10000, $currency),
    TaxRate::fromPartsPerMillion(230000)
);

$cod = new OrderData(
    '42', 'processing', '2026-08-12T10:00:00+00:00', '', $currency,
    Language::fromTag('pl-PL'), $seller, $buyer, [$item], 'cod'
);
$policy = new CodOrderPolicy();
$request = (new OrderMapper())->map($cod, $policy, '2026-08-12T11:00:00+00:00');
$metadata = $request->metadata;
$check('manual COD policy qualifies unpaid cod', $policy->documentTypeFor($cod)->value() === 'order_confirmation');
$check('COD decision is explicitly unpaid', ($metadata['payment_status'] ?? '') === 'cash_on_delivery_unpaid');
$check('COD notice is immutable snapshot metadata', strpos((string) ($metadata['payment_notice'] ?? ''), 'Nieop') === 0);
$snapshot = DocumentSnapshot::create(
    'doc_beta', 'ORDER_CONFIRMATION/2026/000001', $request->type, $request->status,
    $request->sourceType, $request->sourceId, $request->currency, $request->language,
    $request->seller, $request->buyer, $request->items, $request->createdAt,
    $request->issuedAt, $request->version, $request->metadata
);
$html = (new HtmlRenderer(new TemplateCatalog()))->render($snapshot, 2);
$check('HTML contains COD notice', strpos($html, 'Nieop') !== false);

$paid = new OrderData(
    '43', 'processing', '2026-08-12T10:00:00+00:00', '2026-08-12T10:05:00+00:00',
    $currency, Language::fromTag('pl-PL'), $seller, $buyer, [$item], 'cod'
);
$check('paid COD is rejected by unpaid-only policy', $policy->documentTypeFor($paid) === null);
$inactive = new OrderData(
    '44', 'cancelled', '2026-08-12T10:00:00+00:00', '', $currency,
    Language::fromTag('pl-PL'), $seller, $buyer, [$item], 'cod'
);
$check('cancelled COD is rejected', $policy->documentTypeFor($inactive) === null);

$native = (new NativeOrderAdapter($seller, Language::fromTag('pl-PL'), 2))->map(new class {
    public function get_id(): int { return 45; }
    public function get_status(): string { return 'processing'; }
    public function get_currency(): string { return 'PLN'; }
    public function get_date_created(): object { return new class { public function date(string $format): string { return '2026-08-12T10:00:00+00:00'; } }; }
    public function get_date_paid(): object { return new class { public function date(string $format): string { return ''; } }; }
    public function get_payment_method(): string { return 'cod'; }
    public function get_items(string $type): array { return $type === 'line_item' ? [new class {
        public function get_name(): string { return 'Granite item'; }
        public function get_quantity(): int { return 1; }
        public function get_total(): string { return '100.00'; }
        public function get_total_tax(): string { return '23.00'; }
        public function get_taxes(): array { return ['total' => [1 => '23.00']]; }
        public function get_meta(string $key, bool $single): string { return 'szt.'; }
    }] : []; }
    public function get_shipping_total(): string { return '0'; }
    public function get_shipping_tax(): string { return '0'; }
    public function get_meta(string $key, bool $single): string { return ''; }
    public function get_billing_company(): string { return 'Buyer'; }
    public function get_billing_first_name(): string { return ''; }
    public function get_billing_last_name(): string { return ''; }
    public function get_billing_email(): string { return 'buyer@example.invalid'; }
    public function get_billing_address_1(): string { return 'Testowa 1'; }
    public function get_billing_address_2(): string { return ''; }
    public function get_billing_postcode(): string { return '00-001'; }
    public function get_billing_city(): string { return 'Warszawa'; }
    public function get_billing_state(): string { return ''; }
    public function get_billing_country(): string { return 'PL'; }
});
$check('NativeOrderAdapter maps WooCommerce-shaped order', $native->currency->code() === 'PLN' && $native->paymentMethod === 'cod');
$check('NativeOrderAdapter preserves the authoritative WooCommerce rate', $native->items[0]->taxRate()->partsPerMillion() === 230000);

$roundedNative = (new NativeOrderAdapter($seller, Language::fromTag('pl-PL'), 2))->map(new class {
    public function get_id(): int { return 46; }
    public function get_status(): string { return 'processing'; }
    public function get_currency(): string { return 'PLN'; }
    public function get_date_created(): object { return new class { public function date(string $format): string { return '2026-08-12T10:00:00+00:00'; } }; }
    public function get_date_paid(): object { return new class { public function date(string $format): string { return '2026-08-12T10:05:00+00:00'; } }; }
    public function get_payment_method(): string { return 'bacs'; }
    public function get_items(string $type): array { return $type === 'line_item' ? [new class {
        public function get_name(): string { return 'Rounded line'; }
        public function get_quantity(): int { return 1; }
        public function get_total(): string { return '25.58'; }
        public function get_total_tax(): string { return '5.88'; }
        public function get_taxes(): array { return ['total' => [1 => '5.88']]; }
        public function get_meta(string $key, bool $single): string { return 'szt.'; }
    }] : []; }
    public function get_shipping_total(): string { return '0'; }
    public function get_shipping_tax(): string { return '0'; }
    public function get_meta(string $key, bool $single): string { return ''; }
    public function get_billing_company(): string { return 'Buyer'; }
    public function get_billing_first_name(): string { return ''; }
    public function get_billing_last_name(): string { return ''; }
    public function get_billing_email(): string { return 'buyer@example.invalid'; }
    public function get_billing_address_1(): string { return 'Testowa 1'; }
    public function get_billing_address_2(): string { return ''; }
    public function get_billing_postcode(): string { return '00-001'; }
    public function get_billing_city(): string { return 'Warszawa'; }
    public function get_billing_state(): string { return ''; }
    public function get_billing_country(): string { return 'PL'; }
});
$check('rounded WooCommerce line still snapshots as 23%', $roundedNative->items[0]->taxRate()->partsPerMillion() === 230000, (string) $roundedNative->items[0]->taxRate()->partsPerMillion());

$pdf = (new BasicPdfRenderer(2))->render($snapshot);
$check('PDF contains the unpaid COD marker', strpos($pdf, 'NIEOP') !== false);

echo "checks={$pass} pass={$pass} fail={$fail}" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
