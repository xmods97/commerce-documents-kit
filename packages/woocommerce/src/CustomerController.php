<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use Throwable;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\WordPress\ConfigKeyProvider;
use Xmods\CommerceDocuments\WordPress\EncryptedSnapshotCodec;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;
use Xmods\CommerceDocuments\WordPress\WpdbDocumentRepository;

/**
 * Adds read-only PDF actions to the authenticated WooCommerce customer area.
 *
 * The request never trusts an order id from the browser: the document snapshot
 * is loaded first, its immutable WooCommerce source is checked, and only then
 * is the current account compared with the order owner.
 */
final class CustomerController
{
    public static function boot(): void
    {
        add_filter(
            'woocommerce_my_account_my_orders_actions',
            [self::class, 'myOrdersActions'],
            20,
            2
        );
        add_action(
            'woocommerce_order_details_after_order_table',
            [self::class, 'orderDetailsActions'],
            20,
            1
        );
        add_action('wp_enqueue_scripts', [self::class, 'enqueueCustomerStyles']);
        add_action('admin_post_commerce_documents_customer_pdf', [self::class, 'customerPdf']);
    }

    public static function enqueueCustomerStyles(): void
    {
        if (!function_exists('is_account_page') || !is_account_page()
            || !function_exists('wp_register_style')
            || !function_exists('wp_add_inline_style')) {
            return;
        }

        $handle = 'commerce-documents-customer';
        wp_register_style($handle, false, [], '1.0.0');
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, '.woocommerce-account .account-orders-table .woocommerce-orders-table__cell-order-actions{white-space:normal}.woocommerce-account .account-orders-table .woocommerce-orders-table__cell-order-actions>a{display:block;width:180px;max-width:100%;box-sizing:border-box;margin:0 0 8px;text-align:center;white-space:nowrap}.woocommerce-account .account-orders-table .woocommerce-orders-table__cell-order-actions>a:last-child{margin-bottom:0}');
    }

    /** @param array<string, array<string, string>> $actions */
    public static function myOrdersActions(array $actions, $order): array
    {
        if (!self::canViewOrder($order)) {
            return $actions;
        }
        foreach (self::documentsForOrder($order) as $document) {
            $key = 'commerce-document-' . $document['document_id'];
            $actions[$key] = [
                'url' => self::pdfUrl($document['document_id'], 'download'),
                'name' => self::shortActionLabel($document['type']),
            ];
        }
        return $actions;
    }

    /** @param mixed $order */
    public static function orderDetailsActions($order): void
    {
        if (!self::canViewOrder($order)) {
            return;
        }
        $documents = self::documentsForOrder($order);
        if ($documents === []) {
            return;
        }

        echo '<section class="commerce-documents-customer-actions" style="margin:24px 0">'
            . '<h2>Documents</h2>'
            . '<p>Internal order confirmations. These are not fiscal invoices.</p>';
        foreach ($documents as $document) {
            echo '<p><strong>' . esc_html($document['label']) . '</strong> '
                . '<a class="button" target="_blank" rel="noopener" href="'
                . esc_url(self::pdfUrl($document['document_id'], 'print')) . '">Print PDF</a> '
                . '<a class="button" href="'
                . esc_url(self::pdfUrl($document['document_id'], 'download')) . '">Download PDF</a></p>';
        }
        echo '</section>';
    }

    public static function customerPdf(): void
    {
        if (!is_user_logged_in() && !current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }

        $documentId = isset($_GET['document_id'])
            ? sanitize_text_field(wp_unslash($_GET['document_id']))
            : '';
        $mode = isset($_GET['mode'])
            ? sanitize_key(wp_unslash($_GET['mode']))
            : '';
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $documentId) !== 1
            || !in_array($mode, ['download', 'print'], true)) {
            wp_die('Invalid document request.', '', ['response' => 400]);
        }
        check_admin_referer('commerce_documents_customer_pdf_' . $mode . '_' . $documentId);

        $snapshot = self::find($documentId);
        if ($snapshot === null || !self::snapshotIsCustomerDocument($snapshot)) {
            wp_die('Document not found.', '', ['response' => 404]);
        }

        $data = $snapshot->toArray();
        $sourceId = (string) ($data['source_id'] ?? '');
        if (preg_match('/^[1-9][0-9]*$/D', $sourceId) !== 1 || !function_exists('wc_get_order')) {
            wp_die('Document not found.', '', ['response' => 404]);
        }
        $order = wc_get_order((int) $sourceId);
        if (!self::canViewOrder($order)) {
            wp_die('Forbidden', '', ['response' => 403]);
        }

        try {
            $pdf = AdminController::renderSnapshotPdf($snapshot);
        } catch (Throwable $error) {
            error_log('Commerce Documents customer PDF failed: ' . $error->getMessage());
            wp_die('The document could not be rendered.', '', ['response' => 500]);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($mode === 'download' ? 'attachment' : 'inline')
            . '; filename="' . AdminController::snapshotPdfFilename($snapshot) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        echo $pdf;
        exit;
    }

    /** @param mixed $order */
    private static function canViewOrder($order): bool
    {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }
        if (!is_user_logged_in() || !is_object($order) || !method_exists($order, 'get_user_id')) {
            return false;
        }
        $ownerId = (int) $order->get_user_id();
        return $ownerId > 0 && $ownerId === (int) get_current_user_id();
    }

    /** @param mixed $order @return array<int, array{document_id:string,label:string}> */
    private static function documentsForOrder($order): array
    {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return [];
        }
        $orderId = (int) $order->get_id();
        if ($orderId < 1) {
            return [];
        }
        global $wpdb;
        if (!is_object($wpdb)) {
            return [];
        }
        $table = $wpdb->prefix . 'commerce_documents';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT document_id, document_type, source_type, source_id, snapshot, snapshot_cipher,
                    content_hash, superseded_by
             FROM {$table}
             WHERE source_type = %s AND source_id = %s
             ORDER BY created_at ASC, document_id ASC",
            'woocommerce_order',
            (string) $orderId
        ), ARRAY_A);

        $documents = [];
        foreach ((array) $rows as $row) {
            try {
                $snapshot = WpdbDocumentRepository::hydrate($row, self::codec());
            } catch (Throwable $error) {
                $snapshot = null;
            }
            if ($snapshot === null || !self::snapshotIsCustomerDocument($snapshot)) {
                continue;
            }
            if (trim((string) ($row['superseded_by'] ?? '')) !== '') {
                continue;
            }
            $data = $snapshot->toArray();
            $documents[] = [
                'document_id' => (string) $data['document_id'],
                'type' => (string) ($data['document_type'] ?? ''),
                'label' => self::documentLabel((string) ($data['document_type'] ?? ''))
                    . ' ' . (string) ($data['document_number'] ?? ''),
            ];
        }
        return $documents;
    }

    private static function snapshotIsCustomerDocument(DocumentSnapshot $snapshot): bool
    {
        $data = $snapshot->toArray();
        return (string) ($data['source_type'] ?? '') === 'woocommerce_order'
            && in_array((string) ($data['document_type'] ?? ''), DocumentType::issuableValues(), true);
    }

    private static function documentLabel(string $type): string
    {
        return [
            DocumentType::ORDER_CONFIRMATION => 'Order confirmation',
            DocumentType::PAYMENT_CONFIRMATION => 'Payment confirmation',
            DocumentType::CORRECTION => 'Correction',
        ][$type] ?? 'Document';
    }
    private static function shortActionLabel(string $type): string
    {
        return [
            DocumentType::ORDER_CONFIRMATION => 'PDF zamówienia',
            DocumentType::PAYMENT_CONFIRMATION => 'PDF płatności',
            DocumentType::CORRECTION => 'PDF korekty',
        ][$type] ?? 'PDF dokumentu';
    }


    private static function pdfUrl(string $documentId, string $mode): string
    {
        $url = add_query_arg([
            'action' => 'commerce_documents_customer_pdf',
            'document_id' => $documentId,
            'mode' => $mode,
        ], admin_url('admin-post.php'));
        return wp_nonce_url($url, 'commerce_documents_customer_pdf_' . $mode . '_' . $documentId);
    }

    private static function find(string $documentId): ?DocumentSnapshot
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return null;
        }
        $table = $wpdb->prefix . 'commerce_documents';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT document_id, document_type, source_type, source_id, snapshot, snapshot_cipher,
                    content_hash, superseded_by, superseded_at
             FROM {$table} WHERE document_id = %s LIMIT 1",
            $documentId
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return WpdbDocumentRepository::hydrate($row, self::codec());
    }

    private static function codec(): EncryptedSnapshotCodec
    {
        return new EncryptedSnapshotCodec(
            new OpenSslAesGcmCipher(ConfigKeyProvider::encryptionKey())
        );
    }
}
