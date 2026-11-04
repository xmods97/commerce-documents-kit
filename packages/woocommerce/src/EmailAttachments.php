<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use RuntimeException;
use Throwable;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\WordPress\ConfigKeyProvider;
use Xmods\CommerceDocuments\WordPress\EncryptedSnapshotCodec;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;
use Xmods\CommerceDocuments\WordPress\WpdbDocumentRepository;

/** Supplies attachments to WooCommerce; never sends a message itself. */
final class EmailAttachments
{
    /** @var array<string, string> */
    private static $files = [];
    /** @var bool */
    private static $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_filter('woocommerce_email_attachments', [self::class, 'attachments'], 20, 4);
        // After WordPress's shutdown callbacks, including any deferred emails.
        register_shutdown_function([self::class, 'cleanup']);
    }

    /** @param mixed $attachments @param mixed $order @param mixed $email */
    public static function attachments($attachments, string $emailId, $order, $email = null): array
    {
        $attachments = is_array($attachments) ? $attachments : [];
        $map = [
            'customer_on_hold_order' => 'order_confirmation',
            'customer_processing_order' => 'payment_confirmation',
            'customer_completed_order' => 'payment_confirmation',
        ];
        if (!isset($map[$emailId]) || !$order instanceof \WC_Order || $order->get_id() < 1) {
            return $attachments;
        }
        $type = $map[$emailId];
        if ((string) get_option('commerce_documents_wc_' . $type . '_enabled', '0') !== '1') {
            return $attachments;
        }

        try {
            // Email hooks can run before the status observer. Reuse the same
            // policy/idempotency service so hook order cannot omit the PDF.
            $snapshot = $type === 'order_confirmation'
                ? Plugin::generateOrderConfirmationForOrder($order)
                : Plugin::generatePaymentConfirmationForOrder($order);
            $snapshot = self::latestSnapshot($order, $type) ?? $snapshot;
            $path = self::pdfFile($snapshot);
            if (!in_array($path, $attachments, true)) {
                $attachments[] = $path;
            }
        } catch (OrderNotEligibleException $expected) {
            // Never attach a paid document for an unpaid/cancelled order.
        } catch (Throwable $error) {
            // Do not expose document/customer data or prevent the normal email.
            error_log('Commerce Documents email PDF attachment failed.');
        }
        return $attachments;
    }

    private static function latestSnapshot(\WC_Order $order, string $type): ?DocumentSnapshot
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT document_id, document_type, source_type, source_id, snapshot,
                    snapshot_cipher, content_hash, superseded_by
             FROM {$wpdb->prefix}commerce_documents
             WHERE source_type = %s AND source_id = %s AND document_type = %s
               AND (superseded_by IS NULL OR superseded_by = '')
             ORDER BY id DESC LIMIT 1",
            'woocommerce_order', (string) $order->get_id(), $type
        ), ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Document lookup failed.');
        }
        if (!is_array($row)) {
            return null;
        }
        $snapshot = WpdbDocumentRepository::hydrate($row, new EncryptedSnapshotCodec(
            new OpenSslAesGcmCipher(ConfigKeyProvider::encryptionKey())
        ));
        if ($snapshot === null) {
            throw new RuntimeException('Document snapshot unavailable.');
        }
        $data = $snapshot->toArray();
        if ($data['source_type'] !== 'woocommerce_order'
            || $data['source_id'] !== (string) $order->get_id()
            || $data['document_type'] !== $type) {
            throw new RuntimeException('Document source mismatch.');
        }
        return $snapshot;
    }

    private static function pdfFile(DocumentSnapshot $snapshot): string
    {
        $key = hash('sha256', $snapshot->toJson());
        if (isset(self::$files[$key]) && is_file(self::$files[$key])) {
            return self::$files[$key];
        }
        $directory = realpath(sys_get_temp_dir());
        $webRoot = realpath(ABSPATH);
        if ($directory === false || $webRoot === false) {
            throw new RuntimeException('Private temporary directory unavailable.');
        }
        $normalizedDirectory = strtolower(str_replace('\\', '/', $directory));
        $normalizedRoot = rtrim(strtolower(str_replace('\\', '/', $webRoot)), '/');
        if ($normalizedDirectory === $normalizedRoot
            || strpos($normalizedDirectory, $normalizedRoot . '/') === 0) {
            throw new RuntimeException('Temporary PDF directory must be outside the web root.');
        }
        $pdf = AdminController::renderSnapshotPdf($snapshot);
        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            throw new RuntimeException('Invalid PDF output.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'cdk-document-' . bin2hex(random_bytes(16)) . '.pdf';
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Temporary PDF creation failed.');
        }
        try {
            if (!@chmod($path, 0600)) {
                throw new RuntimeException('Temporary PDF permissions failed.');
            }
            $offset = 0;
            $length = strlen($pdf);
            while ($offset < $length) {
                $written = fwrite($handle, substr($pdf, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Temporary PDF write failed.');
                }
                $offset += $written;
            }
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($path);
            throw $error;
        }
        fclose($handle);
        self::$files[$key] = $path;
        return $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$files as $key => $path) {
            if (!is_file($path) || @unlink($path)) {
                unset(self::$files[$key]);
            }
        }
    }
}
