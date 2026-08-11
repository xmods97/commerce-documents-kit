<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use Throwable;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\WordPress\Installer;
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
        $documents = self::documents();

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
            echo '<table class="widefat striped"><thead><tr><th>Number</th><th>Type</th><th>Order</th><th>Created</th><th></th></tr></thead><tbody>';
            foreach ($documents as $document) {
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=commerce_documents_view&document_id=' . rawurlencode($document['document_id'])),
                    'commerce_documents_view_' . $document['document_id']
                );
                echo '<tr><td>' . esc_html($document['document_number']) . '</td><td>'
                    . esc_html($document['document_type']) . '</td><td>#' . esc_html($document['source_id'])
                    . '</td><td>' . esc_html($document['created_at']) . '</td><td><a class="button" target="_blank" href="'
                    . esc_url($url) . '">View / print</a></td></tr>';
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
        echo (new HtmlRenderer(new TemplateCatalog()))->render($snapshot, $exponent);
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

    private static function documents(): array
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return [];
        }
        $table = $wpdb->prefix . 'commerce_documents';
        $rows = $wpdb->get_results(
            "SELECT document_id, document_type, source_id, snapshot, created_at FROM {$table} ORDER BY id DESC LIMIT 50",
            ARRAY_A
        );
        foreach ((array) $rows as &$row) {
            $data = json_decode((string) $row['snapshot'], true);
            $row['document_number'] = is_array($data) ? (string) ($data['document_number'] ?? '') : '';
            unset($row['snapshot']);
        }
        return array_values((array) $rows);
    }

    private static function find(string $documentId): ?DocumentSnapshot
    {
        global $wpdb;
        if (!is_object($wpdb) || $documentId === '') {
            return null;
        }
        $table = $wpdb->prefix . 'commerce_documents';
        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT snapshot FROM {$table} WHERE document_id = %s LIMIT 1",
            $documentId
        ));
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? DocumentSnapshot::fromArray($data) : null;
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
