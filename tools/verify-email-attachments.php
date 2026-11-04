<?php

declare(strict_types=1);

// Framework-free boundary test. Stubs exist only in this separate CLI process.
namespace {
    define('ABSPATH', dirname(__DIR__) . '/plugins/commerce-documents-woocommerce/');
    define('ARRAY_A', 'ARRAY_A');
    define('COMMERCE_DOCUMENTS_ENCRYPTION_KEY', base64_encode(random_bytes(32)));
    require __DIR__ . '/runtime-autoload.php';
    $options = [];
    $hooks = [];
    function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
    function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name][] = $callback; }
    class WC_Order {
        public $eligible = true;
        public function get_id() { return 123; }
    }
}

namespace Xmods\CommerceDocuments\WooCommerce {
    final class Plugin {
        public static $snapshot;
        public static $requestedType;
        public static function generateOrderConfirmationForOrder($order) {
            self::$requestedType = 'order_confirmation';
            if (!$order->eligible) { throw new OrderNotEligibleException('Synthetic unpaid/ineligible order'); }
            return self::$snapshot;
        }
        public static function generatePaymentConfirmationForOrder($order) {
            self::$requestedType = 'payment_confirmation';
            if (!$order->eligible) { throw new OrderNotEligibleException('Synthetic unpaid/ineligible order'); }
            return self::$snapshot;
        }
    }
    final class AdminController {
        public static $fail = false;
        public static function renderSnapshotPdf($snapshot) {
            if (self::$fail) { throw new \RuntimeException('Synthetic PDF error'); }
            return "%PDF-1.4\n" . $snapshot->toJson();
        }
    }
}

namespace {
    use Xmods\CommerceDocuments\{Address, Currency, DocumentItem, DocumentSnapshot, DocumentStatus, DocumentType, Language, Money, Party, Quantity, TaxRate};
    use Xmods\CommerceDocuments\WooCommerce\{AdminController, EmailAttachments, Plugin};

    $checks = 0;
    function check($condition, $message) {
        if (!$condition) { throw new RuntimeException($message); }
        ++$GLOBALS['checks'];
        echo 'PASS ' . $message . PHP_EOL;
    }
    function snapshot($type, $id) {
        $currency = Currency::fromCode('PLN');
        $party = Party::create('Synthetic test', 'TEST', 'buyer@example.invalid', Address::create('Test 1', '', '00-001', 'Test', '', 'PL'));
        $item = DocumentItem::fromSnapshotAmounts('Test', Quantity::one(), 'szt.', Money::fromMinorUnits(100, $currency), TaxRate::zero(), Money::fromMinorUnits(100, $currency), Money::zero($currency));
        return DocumentSnapshot::create($id, 'TEST/2026/1', DocumentType::fromString($type), DocumentStatus::fromString('issued'), 'woocommerce_order', '123', $currency, Language::fromTag('pl-PL'), $party, $party, [$item], '2026-11-04T12:00:00+01:00', '2026-11-04T12:00:00+01:00');
    }
    $wpdb = new class {
        public $prefix = 'test_';
        public $last_error = '';
        public $row;
        public $fail = false;
        public function prepare($sql, ...$args) { return $sql; }
        public function get_row($sql, $output) {
            $this->last_error = $this->fail ? 'Synthetic lookup failure' : '';
            return $this->fail ? null : $this->row;
        }
    };
    function storeSnapshot($snapshot) {
        $data = $snapshot->toArray();
        $GLOBALS['wpdb']->row = ['document_id' => $data['document_id'], 'snapshot' => $snapshot->toJson(), 'content_hash' => hash('sha256', $snapshot->toJson())];
    }

    EmailAttachments::boot();
    EmailAttachments::boot();
    check(count($hooks['woocommerce_email_attachments']) === 1, 'boot registers exactly one filter');
    $order = new WC_Order();
    $base = ['third-party.pdf'];
    Plugin::$snapshot = snapshot('order_confirmation', 'doc_original');
    storeSnapshot(snapshot('order_confirmation', 'doc_rebuilt'));
    check(EmailAttachments::attachments($base, 'customer_on_hold_order', $order) === $base, 'disabled setting preserves existing attachments');
    $options['commerce_documents_wc_order_confirmation_enabled'] = '1';
    $options['commerce_documents_wc_payment_confirmation_enabled'] = '1';
    $paths = EmailAttachments::attachments($base, 'customer_on_hold_order', $order);
    check(count($paths) === 2 && $paths[0] === 'third-party.pdf' && is_file($paths[1]), 'on-hold email preserves third-party attachment and adds PDF');
    $firstPath = $paths[1];
    check(Plugin::$requestedType === 'order_confirmation', 'on-hold requests order confirmation');
    check(strpos(file_get_contents($firstPath), 'doc_rebuilt') !== false, 'newest verified snapshot replaces original for email');
    check(substr($firstPath, -4) === '.pdf' && strpos(strtolower(str_replace('\\', '/', $firstPath)), strtolower(str_replace('\\', '/', ABSPATH))) !== 0, 'PDF is outside public root with PDF extension');
    check(EmailAttachments::attachments($paths, 'customer_on_hold_order', $order) === $paths, 'repeat filter call does not duplicate PDF');
    foreach (['customer_processing_order', 'customer_completed_order'] as $emailId) {
        Plugin::$snapshot = snapshot('payment_confirmation', 'doc_paid');
        storeSnapshot(Plugin::$snapshot);
        $paid = EmailAttachments::attachments([], $emailId, $order);
        check(count($paid) === 1 && Plugin::$requestedType === 'payment_confirmation', $emailId . ' requests payment PDF');
    }
    $order->eligible = false;
    check(EmailAttachments::attachments($base, 'customer_processing_order', $order) === $base, 'ineligible unpaid order has no paid PDF');
    $order->eligible = true;
    check(EmailAttachments::attachments($base, 'new_order', $order) === $base, 'admin email unchanged');
    check(EmailAttachments::attachments($base, 'customer_on_hold_order', null) === $base, 'missing order ignored');
    Plugin::$snapshot = snapshot('order_confirmation', 'doc_original');
    $wpdb->fail = true;
    check(EmailAttachments::attachments($base, 'customer_on_hold_order', $order) === $base, 'lookup failure does not attach stale snapshot');
    $wpdb->fail = false;
    storeSnapshot(snapshot('payment_confirmation', 'doc_wrong_type'));
    check(EmailAttachments::attachments($base, 'customer_on_hold_order', $order) === $base, 'wrong document type rejected');
    storeSnapshot(snapshot('order_confirmation', 'doc_render_error'));
    AdminController::$fail = true;
    check(EmailAttachments::attachments($base, 'customer_on_hold_order', $order) === $base, 'PDF failure preserves original email');
    AdminController::$fail = false;
    EmailAttachments::cleanup();
    check(!file_exists($firstPath) && !file_exists($paid[0]), 'temporary files removed');
    EmailAttachments::cleanup();
    check(true, 'repeated cleanup is safe');
    echo $checks . " checks passed; no WordPress, DB, network or mail transport used.\n";
}
