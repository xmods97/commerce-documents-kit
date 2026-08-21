<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/packages/woocommerce/src/CustomerController.php');
$plugin = (string) file_get_contents($root . '/packages/woocommerce/src/Plugin.php');

$checks = 0;
$passes = 0;
$check = static function (string $name, bool $condition) use (&$checks, &$passes): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $passes++;
    echo "PASS: {$name}\n";
};

$check('customer controller is booted on frontend', strpos($plugin, 'CustomerController::boot();') !== false);
$check('orders list hook is registered', strpos($controller, 'woocommerce_my_account_my_orders_actions') !== false);
$check('duplicate order details section is disabled', strpos($controller, 'woocommerce_order_details_after_order_table') === false
    && strpos($controller, 'commerce-documents-customer-actions') === false);
$check('customer PDF endpoint is registered on admin_post', strpos($controller, "admin_post_commerce_documents_customer_pdf") !== false);
$check('no unauthenticated customer PDF endpoint exists', strpos($controller, 'admin_post_nopriv_commerce_documents_customer_pdf') === false);
$check('endpoint refuses unauthenticated requests', strpos($controller, "if (!is_user_logged_in() && !current_user_can('manage_woocommerce'))") !== false);
$check('nonce is checked after request mode and document id are validated', strpos($controller, "check_admin_referer('commerce_documents_customer_pdf_' . \$mode . '_' . \$documentId)") !== false);
$check('nonce is bound to both print/download mode and document', substr_count($controller, 'commerce_documents_customer_pdf_') >= 2);
$check('document is loaded from the database', strpos($controller, 'WpdbDocumentRepository::hydrate($row, self::codec())') !== false);
$check('document source is restricted to WooCommerce orders', strpos($controller, "source_type = %s AND source_id = %s") !== false);
$check('order is loaded through WooCommerce CRUD', strpos($controller, 'wc_get_order((int) $sourceId)') !== false);
$check('customer access compares the account to order owner', strpos($controller, 'get_user_id') !== false && strpos($controller, 'get_current_user_id') !== false);
$check('admin access remains possible for support', strpos($controller, "current_user_can('manage_woocommerce')") !== false);
$check('legacy fiscal types cannot be exposed', strpos($controller, 'DocumentType::issuableValues()') !== false);
$check('replaced documents are hidden from customer actions', strpos($controller, "superseded_by") !== false);
$check('download links are nonce-signed', strpos($controller, "self::pdfUrl(\$document['document_id'], 'download')") !== false
    && strpos($controller, 'wp_nonce_url') !== false);
$check('print uses inline PDF disposition', strpos($controller, "mode === 'download' ? 'attachment' : 'inline'") !== false);
$check('download filename comes from the immutable snapshot', strpos($controller, 'AdminController::snapshotPdfFilename($snapshot)') !== false);
$check('customer path reuses the single production renderer seam', strpos($controller, 'AdminController::renderSnapshotPdf($snapshot)') !== false);
$check('customer path has no mail or remote transport', preg_match('/\b(wp_mail|mail|fsockopen|curl_\w+|wp_remote_\w+)\s*\(/', $controller) === 0);

echo "checks={$checks} pass={$passes} fail=" . ($checks - $passes) . "\n";
exit($checks === $passes ? 0 : 1);
