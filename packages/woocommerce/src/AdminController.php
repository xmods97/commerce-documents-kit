<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use Throwable;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\IdempotencyKey;
use Xmods\CommerceDocuments\WordPress\ConfigKeyProvider;
use Xmods\CommerceDocuments\WordPress\EncryptedSnapshotCodec;
use Xmods\CommerceDocuments\WordPress\Installer;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;
use Xmods\CommerceDocuments\WordPress\WpdbDocumentRepository;
use Xmods\CommerceDocuments\WordPress\WpdbEventLogger;
use Xmods\CommerceDocuments\WordPress\WpdbNumberGenerator;
use Xmods\CommerceDocuments\Rendering\HtmlRenderer;
use Xmods\CommerceDocuments\Rendering\TemplateCatalog;

final class AdminController
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_commerce_documents_generate', [self::class, 'generate']);
        add_action('admin_post_commerce_documents_view', [self::class, 'view']);
        add_action('admin_post_commerce_documents_migrate', [self::class, 'migrate']);
        add_action('admin_post_commerce_documents_correct', [self::class, 'correct']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            'Commerce Documents',
            'Commerce Documents',
            'manage_woocommerce',
            'commerce-documents',
            [self::class, 'page']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('commerce_documents', 'commerce_documents_wc_settings', [
            'type' => 'array',
            'sanitize_callback' => static function ($input): array {
                $statuses = array_map(
                    static function (string $status): string {
                        return strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
                    },
                    array_keys(function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [])
                );
                return AdminSettings::sanitize((array) $input, $statuses);
            },
        ]);
        register_setting('commerce_documents', 'commerce_documents_wc_shadow_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => static function ($value): bool {
                return (string) $value === '1';
            },
            'default' => false,
        ]);
    }

    public static function page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to manage commerce documents.', 'commerce-documents-woocommerce'));
        }
        $settings = (array) get_option('commerce_documents_wc_settings', []);
        $sellerSource = ($settings['seller_source'] ?? 'manual') === 'woocommerce'
            ? 'woocommerce'
            : 'manual';
        $seller = AdminSettings::resolveSeller(
            $settings,
            static function (string $name, $default) {
                return get_option($name, $default);
            }
        );
        $address = (array) ($seller['address'] ?? []);
        $proforma = (array) ($settings['proforma_statuses'] ?? []);
        $invoice = (array) ($settings['invoice_statuses'] ?? []);
        $enabled = get_option('commerce_documents_wc_shadow_enabled', false) === true;
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $search = isset($_GET['cdk_search']) ? sanitize_text_field(wp_unslash($_GET['cdk_search'])) : '';
        $documents = self::documents($search);

        echo '<div class="wrap"><h1>Commerce Documents</h1>';
        self::notice();
        echo '<p><strong>Test mode:</strong> documents are stored locally. No email, PDF or KSeF submission is performed.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('commerce_documents');
        echo '<h2>Seller</h2><fieldset><label><input type="radio" name="commerce_documents_wc_settings[seller_source]" value="woocommerce" '
            . checked($sellerSource, 'woocommerce', false) . '> Use WooCommerce store details</label><br>'
            . '<label><input type="radio" name="commerce_documents_wc_settings[seller_source]" value="manual" '
            . checked($sellerSource, 'manual', false) . '> Enter seller details manually</label></fieldset>'
            . '<p class="description">WooCommerce supplies the store name, email and address. Tax identifier remains explicit because WooCommerce core has no seller VAT/NIP field.</p>'
            . '<table class="form-table">';
        self::field('Company / name', 'name', (string) ($seller['name'] ?? ''), 'text', $sellerSource === 'woocommerce');
        self::field('Tax identifier', 'tax_identifier', (string) ($seller['tax_identifier'] ?? ''));
        self::field('Email', 'email', (string) ($seller['email'] ?? ''), 'email', $sellerSource === 'woocommerce');
        self::addressField('Address line 1', 'line1', (string) ($address['line1'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField('Address line 2', 'line2', (string) ($address['line2'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField('Postal code', 'postal_code', (string) ($address['postal_code'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField('City', 'city', (string) ($address['city'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField('Region', 'region', (string) ($address['region'] ?? ''), $sellerSource === 'woocommerce');
        self::addressField('Country code', 'country_code', (string) ($address['country_code'] ?? ''), $sellerSource === 'woocommerce');
        echo '<tr><th>Document language</th><td><select name="commerce_documents_wc_settings[language]">';
        foreach (['pl-PL' => 'Polski', 'en' => 'English'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['language'] ?? 'pl-PL', $value, false) . '>'
                . esc_html($label) . '</option>';
        }
        echo '</select></td></tr></table><h2>Automation</h2>';
        echo '<input type="hidden" name="commerce_documents_wc_shadow_enabled" value="0">';
        echo '<label><input type="checkbox" name="commerce_documents_wc_shadow_enabled" value="1" '
            . checked($enabled, true, false) . '> Enable automatic test generation</label>';
        echo '<table class="widefat striped" style="max-width:900px;margin-top:16px"><thead><tr>'
            . '<th>WooCommerce status</th><th>Create proforma</th><th>Create invoice</th></tr></thead><tbody>';
        foreach ($statuses as $key => $label) {
            $status = strpos($key, 'wc-') === 0 ? substr($key, 3) : $key;
            echo '<tr><td>' . esc_html($label) . '</td><td><input type="checkbox" name="commerce_documents_wc_settings[proforma_statuses][]" value="'
                . esc_attr($status) . '" ' . checked(in_array($status, $proforma, true), true, false)
                . '></td><td><input type="checkbox" name="commerce_documents_wc_settings[invoice_statuses][]" value="'
                . esc_attr($status) . '" ' . checked(in_array($status, $invoice, true), true, false) . '></td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Save settings');
        echo '</form><hr><h2>Generate for an existing order</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="commerce_documents_generate">';
        wp_nonce_field('commerce_documents_generate');
        echo '<label>Order ID <input type="number" min="1" required name="order_id"></label> ';
        submit_button('Generate test document', 'secondary', 'submit', false);
        echo '</form><h2>Generated documents</h2>';
        echo '<form method="get"><input type="hidden" name="page" value="commerce-documents">'
            . '<input type="search" name="cdk_search" value="' . esc_attr($search) . '" placeholder="Number, order, type or document ID"> '
            . '<button class="button">Search</button></form>';
        $migration = Installer::preflight();
        echo '<hr><h2>Database migration</h2><p>Installed schema: ' . esc_html((string) $migration['installed_version'])
            . ' / target: ' . esc_html((string) $migration['target_version']) . '</p>';
        if ($migration['upgrade_required']) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="commerce_documents_migrate">';
            wp_nonce_field('commerce_documents_migrate');
            echo '<label><input type="checkbox" name="backup_confirmed" value="1" required> I verified a current database backup.</label> ';
            submit_button('Apply protected schema migration', 'secondary', 'submit', false);
            echo '</form>';
        } else {
            echo '<p>Schema is current. No migration is required.</p>';
        }
        if ($documents === []) {
            echo '<p>No documents have been generated yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Number</th><th>Type</th><th>Order</th><th>Created</th><th>Audit</th><th>Actions</th></tr></thead><tbody>';
            foreach ($documents as $document) {
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=commerce_documents_view&document_id=' . rawurlencode($document['document_id'])),
                    'commerce_documents_view_' . $document['document_id']
                );
                echo '<tr><td>' . esc_html($document['document_number']) . '</td><td>'
                    . esc_html($document['document_type']) . '</td><td>#' . esc_html($document['source_id'])
                    . '</td><td>' . esc_html($document['created_at']) . '</td><td>' . esc_html((string) $document['audit_count']) . '</td><td><a class="button" target="_blank" href="'
                    . esc_url($url) . '">View / print</a>'
                    . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px">'
                    . '<input type="hidden" name="action" value="commerce_documents_correct">'
                    . '<input type="hidden" name="document_id" value="' . esc_attr($document['document_id']) . '">'
                    . '<input type="text" name="correction_note" required maxlength="191" placeholder="Correction note">'
                    . wp_nonce_field('commerce_documents_correct_' . $document['document_id'], '_wpnonce', true, false)
                    . '<button class="button">Create correction</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public static function generate(): void
    {
        self::authorize('commerce_documents_generate');
        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order = $orderId > 0 && function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (!$order) {
            self::redirect('invalid_order');
        }
        try {
            Plugin::generateForOrder($order);
            self::redirect('generated');
        } catch (Throwable $error) {
            self::redirect('failed', $error->getMessage());
        }
    }

    public static function view(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        $documentId = isset($_GET['document_id']) ? sanitize_text_field(wp_unslash($_GET['document_id'])) : '';
        check_admin_referer('commerce_documents_view_' . $documentId);
        $snapshot = self::find($documentId);
        if ($snapshot === null) {
            wp_die('Document not found.', '', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        $exponent = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : null;
        $html = (new HtmlRenderer(new TemplateCatalog()))->render($snapshot, $exponent);
        $audit = '<section style="max-width:900px;margin:24px auto;padding:0 20px"><h2>Audit trail</h2><ul>';
        foreach (self::events($documentId) as $event) {
            $audit .= '<li>' . esc_html($event['created_at'] . ' — ' . $event['event_name']) . '</li>';
        }
        $audit .= '</ul></section>';
        echo str_replace('</body>', $audit . '</body>', $html);
        exit;
    }

    public static function migrate(): void
    {
        self::authorize('commerce_documents_migrate');
        try {
            Installer::migrateToCurrentVersion(isset($_POST['backup_confirmed']) && (string) $_POST['backup_confirmed'] === '1');
            self::redirect('migrated');
        } catch (Throwable $error) {
            self::redirect('failed', $error->getMessage());
        }
    }

    public static function correct(): void
    {
        $documentId = isset($_POST['document_id']) ? sanitize_text_field(wp_unslash($_POST['document_id'])) : '';
        self::authorize('commerce_documents_correct_' . $documentId);
        $note = isset($_POST['correction_note']) ? sanitize_text_field(wp_unslash($_POST['correction_note'])) : '';
        if ($documentId === '' || $note === '') {
            self::redirect('failed', 'Document and correction note are required.');
        }
        try {
            global $wpdb;
            $original = self::find($documentId);
            if ($original === null) {
                throw new \RuntimeException('Document not found.');
            }
            $now = gmdate(DATE_ATOM);
            $data = $original->toArray();
            $sourceId = $documentId . ':' . gmdate('YmdHis') . ':' . bin2hex(random_bytes(8));
            $type = DocumentType::fromString(DocumentType::CORRECTION);
            $key = IdempotencyKey::forSource('commerce_document_correction', $sourceId, $type);
            $data['document_id'] = 'doc_' . substr($key->value(), 0, 24);
            $data['document_number'] = (new WpdbNumberGenerator($wpdb, $wpdb->prefix . 'commerce_document_sequences'))->next($type, $now);
            $data['document_type'] = DocumentType::CORRECTION;
            $data['status'] = DocumentStatus::ISSUED;
            $data['source_type'] = 'commerce_document_correction';
            $data['source_id'] = $sourceId;
            $data['created_at'] = $now;
            $data['issued_at'] = $now;
            $data['version'] = ((int) ($data['version'] ?? 1)) + 1;
            $data['metadata']['correction_of'] = $documentId;
            $data['metadata']['correction_note'] = $note;
            $snapshot = DocumentSnapshot::fromArray($data);
            (new WpdbDocumentRepository($wpdb, $wpdb->prefix . 'commerce_documents', self::codec()))->save($key, $snapshot);
            $wpdb->insert($wpdb->prefix . 'commerce_document_links', [
                'document_id' => $snapshot->toArray()['document_id'],
                'parent_document_id' => $documentId,
                'relationship' => 'correction',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ], ['%s', '%s', '%s', '%s']);
            (new WpdbEventLogger($wpdb, $wpdb->prefix . 'commerce_document_events', ConfigKeyProvider::auditKey()))->record(
                'document.corrected',
                $snapshot->toArray()['document_id'],
                ['parent_document_id' => $documentId]
            );
            self::redirect('corrected');
        } catch (Throwable $error) {
            self::redirect('failed', $error->getMessage());
        }
    }

    private static function authorize(string $nonce): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer($nonce);
    }

    private static function redirect(string $result, string $message = ''): void
    {
        $url = add_query_arg(
            ['page' => 'commerce-documents', 'cdk_result' => $result, 'cdk_message' => $message],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function documents(string $search = ''): array
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return [];
        }
        $table = $wpdb->prefix . 'commerce_documents';
        $rows = $wpdb->get_results(
            "SELECT document_id, document_type, source_id, snapshot, snapshot_cipher, created_at FROM {$table} ORDER BY id DESC LIMIT 50",
            ARRAY_A
        );
        $filtered = [];
        foreach ((array) $rows as $row) {
            $data = self::decodeRow($row);
            $row['document_number'] = is_array($data) ? (string) ($data['document_number'] ?? '') : '';
            $row['audit_count'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}commerce_document_events WHERE document_id = %s",
                $row['document_id']
            ));
            if ($search !== '' && stripos(implode(' ', [(string) $row['document_id'], (string) $row['document_number'], (string) $row['document_type'], (string) $row['source_id']]), $search) === false) {
                continue;
            }
            unset($row['snapshot']);
            unset($row['snapshot_cipher']);
            $filtered[] = $row;
        }
        return $filtered;
    }

    private static function find(string $documentId): ?DocumentSnapshot
    {
        global $wpdb;
        if (!is_object($wpdb) || $documentId === '') {
            return null;
        }
        $table = $wpdb->prefix . 'commerce_documents';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT document_id, snapshot, snapshot_cipher FROM {$table} WHERE document_id = %s LIMIT 1",
            $documentId
        ), ARRAY_A);
        $data = is_array($row) ? self::decodeRow($row) : null;
        return is_array($data) ? DocumentSnapshot::fromArray($data) : null;
    }

    private static function decodeRow(array $row): ?array
    {
        if ((string) ($row['snapshot_cipher'] ?? '') !== '') {
            return self::codec()->decrypt((string) $row['snapshot_cipher'], (string) $row['document_id'])->toArray();
        }
        $data = json_decode((string) ($row['snapshot'] ?? ''), true);
        return is_array($data) ? $data : null;
    }

    private static function events(string $documentId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'commerce_document_events';
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT event_name, created_at FROM {$table} WHERE document_id = %s ORDER BY id ASC",
            $documentId
        ), ARRAY_A);
    }

    private static function codec(): EncryptedSnapshotCodec
    {
        return new EncryptedSnapshotCodec(new OpenSslAesGcmCipher(ConfigKeyProvider::encryptionKey()));
    }

    private static function field(
        string $label,
        string $key,
        string $value,
        string $type = 'text',
        bool $readOnly = false
    ): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><input class="regular-text" type="' . esc_attr($type)
            . '" name="commerce_documents_wc_settings[seller][' . esc_attr($key) . ']" value="' . esc_attr($value) . '"'
            . ($readOnly ? ' readonly' : '') . '></td></tr>';
    }

    private static function addressField(string $label, string $key, string $value, bool $readOnly = false): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><input class="regular-text" type="text" name="commerce_documents_wc_settings[seller][address]['
            . esc_attr($key) . ']" value="' . esc_attr($value) . '"'
            . ($readOnly ? ' readonly' : '') . '></td></tr>';
    }

    private static function notice(): void
    {
        $result = isset($_GET['cdk_result']) ? sanitize_key((string) $_GET['cdk_result']) : '';
        if ($result === 'generated') {
            echo '<div class="notice notice-success"><p>Document generated or already existed.</p></div>';
        } elseif ($result !== '') {
            $message = isset($_GET['cdk_message']) ? sanitize_text_field(wp_unslash($_GET['cdk_message'])) : '';
            echo '<div class="notice notice-error"><p>' . esc_html($message !== '' ? $message : 'Operation failed.') . '</p></div>';
        }
    }
}
