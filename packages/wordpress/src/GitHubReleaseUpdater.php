<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

/**
 * Supplies WordPress with checksum-verified updates from a private GitHub
 * Release. The GitHub token is deliberately read only from wp-config.php.
 */
final class GitHubReleaseUpdater
{
    private const OWNER = 'xmods97';
    private const REPOSITORY = 'commerce-documents-kit';
    private const ZIP_ASSET = 'commerce-documents-woocommerce.zip';
    private const CHECKSUM_ASSET = 'commerce-documents-woocommerce.zip.sha256';
    private const CACHE_KEY = 'commerce_documents_github_release';
    private const CACHE_TTL = 21600;
    private const CHECK_ACTION = 'commerce_documents_check_update';
    private const CHECK_NONCE = 'commerce_documents_check_update';

    /** @var self|null */
    private static $instance;

    /** @var string */
    private $pluginBasename;

    /** @var string */
    private $currentVersion;

    private function __construct(string $pluginFile)
    {
        $this->pluginBasename = function_exists('plugin_basename')
            ? plugin_basename($pluginFile)
            : basename(dirname($pluginFile)) . '/' . basename($pluginFile);
        $this->currentVersion = $this->readPluginVersion($pluginFile);
    }

    public static function boot(string $pluginFile): void
    {
        if (!function_exists('add_filter')) {
            return;
        }

        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self($pluginFile);
        add_filter('pre_set_site_transient_update_plugins', [self::$instance, 'filterUpdates']);
        add_filter('plugins_api', [self::$instance, 'pluginInformation'], 20, 3);
        add_filter('upgrader_pre_download', [self::$instance, 'preDownload'], 10, 3);
        add_action('load-update-core.php', [self::$instance, 'primeCache']);
        add_action('admin_post_' . self::CHECK_ACTION, [self::$instance, 'checkUpdate']);
    }

    /** @param mixed $transient */
    public function filterUpdates($transient)
    {
        if (!is_object($transient) || !isset($transient->checked) || !$this->canCheckUpdates()) {
            return $transient;
        }

        $release = $this->release(false);
        if ($release === null || version_compare($release['version'], $this->currentVersion, '<=')) {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[$this->pluginBasename] = (object) [
            'slug' => dirname($this->pluginBasename),
            'plugin' => $this->pluginBasename,
            'new_version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['package_url'],
            'tested' => '6.8',
        ];

        return $transient;
    }

    /** @param mixed $result @param mixed $action @param mixed $args */
    public function pluginInformation($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args)) {
            return $result;
        }

        $slug = isset($args->slug) ? (string) $args->slug : '';
        if ($slug !== dirname($this->pluginBasename)) {
            return $result;
        }

        $release = $this->release(false);
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => 'Commerce Documents for WooCommerce',
            'slug' => dirname($this->pluginBasename),
            'version' => $release['version'],
            'author' => '<a href="https://github.com/xmods97">xmods97</a>',
            'homepage' => 'https://github.com/' . self::OWNER . '/' . self::REPOSITORY,
            'download_link' => $release['package_url'],
            'sections' => [
                'description' => 'Internal WooCommerce order and payment confirmations.',
                'changelog' => nl2br(esc_html($release['body'])),
            ],
        ];
    }

    /** @param mixed $reply @param mixed $package @param mixed $upgrader */
    public function preDownload($reply, $package, $upgrader)
    {
        unset($upgrader);
        $package = (string) $package;
        if (!$this->isAllowedPackageUrl($package)) {
            return $reply;
        }

        $release = $this->release(false);
        if ($release === null || $release['package_url'] !== $package) {
            return new \WP_Error(
                'commerce_documents_update_rejected',
                'The Commerce Documents update package is not a verified current release.'
            );
        }

        if ($this->token() === '') {
            return new \WP_Error(
                'commerce_documents_update_token_missing',
                'Configure COMMERCE_DOCUMENTS_GITHUB_TOKEN in wp-config.php before updating.'
            );
        }

        $response = wp_remote_get($package, [
            'timeout' => 45,
            'redirection' => 3,
            'headers' => $this->headers('application/octet-stream'),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status !== 200 || $body === '' || strlen($body) > 26214400) {
            return new \WP_Error(
                'commerce_documents_update_download_failed',
                'The Commerce Documents update package could not be downloaded.'
            );
        }

        if (!hash_equals($release['sha256'], hash('sha256', $body))) {
            return new \WP_Error(
                'commerce_documents_update_checksum_failed',
                'The Commerce Documents update checksum does not match the GitHub Release.'
            );
        }

        $temporaryFile = wp_tempnam(self::ZIP_ASSET);
        if (!$temporaryFile || file_put_contents($temporaryFile, $body, LOCK_EX) === false) {
            return new \WP_Error(
                'commerce_documents_update_temp_failed',
                'The Commerce Documents update package could not be written to a temporary file.'
            );
        }

        return $temporaryFile;
    }

    public function primeCache(): void
    {
        if ($this->canCheckUpdates()) {
            $this->release(false);
        }
    }

    public function checkUpdate(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to check Commerce Documents updates.', 'commerce-documents-woocommerce'));
        }
        check_admin_referer(self::CHECK_NONCE);

        delete_transient(self::CACHE_KEY);
        $release = $this->release(true);
        $status = $release === null
            ? 'error'
            : (version_compare($release['version'], $this->currentVersion, '>') ? 'available' : 'current');

        $url = add_query_arg(
            ['page' => 'commerce-documents-settings', 'cdk_update' => $status],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    public function checkUrl(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . self::CHECK_ACTION),
            self::CHECK_NONCE
        );
    }

    public function currentVersion(): string
    {
        return $this->currentVersion;
    }

    public function cachedRelease(): ?array
    {
        $release = get_transient(self::CACHE_KEY);
        return is_array($release) && isset($release['version'], $release['package_url'])
            ? $release
            : null;
    }

    private function release(bool $force): ?array
    {
        if (!$force) {
            $cached = $this->cachedRelease();
            if ($cached !== null) {
                return $cached;
            }
        }

        if ($this->token() === '') {
            return null;
        }

        $apiUrl = 'https://api.github.com/repos/' . self::OWNER . '/' . self::REPOSITORY . '/releases/latest';
        $response = $this->apiGet($apiUrl, 'application/vnd.github+json');
        if ($response === null || !isset($response['tag_name'], $response['assets']) || !is_array($response['assets'])) {
            return null;
        }

        $zip = null;
        $checksum = null;
        foreach ($response['assets'] as $asset) {
            if (!is_array($asset) || !isset($asset['name'], $asset['url'], $asset['id'])) {
                continue;
            }
            if ($asset['name'] === self::ZIP_ASSET) {
                $zip = $asset;
            }
            if ($asset['name'] === self::CHECKSUM_ASSET) {
                $checksum = $asset;
            }
        }

        if ($zip === null || $checksum === null) {
            return null;
        }

        // GitHub's release-asset API requires the binary asset media type here,
        // including for a plain-text checksum asset.
        $checksumBody = $this->apiGetBody((string) $checksum['url'], 'application/octet-stream');
        $sha256 = $this->parseChecksum($checksumBody);
        if ($sha256 === null) {
            return null;
        }

        $version = $this->normalizeVersion((string) $response['tag_name']);
        if ($version === '') {
            return null;
        }

        $release = [
            'version' => $version,
            'tag' => (string) $response['tag_name'],
            'html_url' => isset($response['html_url']) ? (string) $response['html_url'] : '',
            'body' => isset($response['body']) ? (string) $response['body'] : '',
            'package_url' => (string) $zip['url'],
            'sha256' => $sha256,
        ];
        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    /** @return array<string, mixed>|null */
    private function apiGet(string $url, string $accept): ?array
    {
        $body = $this->apiGetBody($url, $accept);
        if ($body === null) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function apiGetBody(string $url, string $accept): ?string
    {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 2,
            'headers' => $this->headers($accept),
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        return (string) wp_remote_retrieve_body($response);
    }

    /** @return array<string, string> */
    private function headers(string $accept): array
    {
        $headers = [
            'Accept' => $accept,
            'User-Agent' => 'Commerce-Documents-Updater/' . $this->currentVersion,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ($this->token() !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token();
        }
        return $headers;
    }

    private function token(): string
    {
        return defined('COMMERCE_DOCUMENTS_GITHUB_TOKEN')
            ? trim((string) constant('COMMERCE_DOCUMENTS_GITHUB_TOKEN'))
            : '';
    }

    private function canCheckUpdates(): bool
    {
        return $this->token() !== ''
            && (is_admin() || (function_exists('wp_doing_cron') && wp_doing_cron()));
    }

    private function isAllowedPackageUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'api.github.com') {
            return false;
        }
        $path = (string) ($parts['path'] ?? '');
        return (bool) preg_match(
            '#^/repos/' . preg_quote(self::OWNER, '#') . '/' . preg_quote(self::REPOSITORY, '#') . '/releases/assets/[0-9]+$#',
            $path
        );
    }

    private function parseChecksum(?string $body): ?string
    {
        if ($body === null || !preg_match('/\b([a-f0-9]{64})\b/i', $body, $matches)) {
            return null;
        }
        return strtolower($matches[1]);
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);
        if (strpos($version, 'v') === 0 || strpos($version, 'V') === 0) {
            $version = substr($version, 1);
        }
        return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version)
            ? $version
            : '';
    }

    private function readPluginVersion(string $pluginFile): string
    {
        if (function_exists('get_file_data')) {
            $data = get_file_data($pluginFile, ['Version' => 'Version'], 'plugin');
            if (isset($data['Version']) && trim((string) $data['Version']) !== '') {
                return trim((string) $data['Version']);
            }
        }

        $contents = @file_get_contents($pluginFile);
        if (is_string($contents) && preg_match('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $contents, $matches)) {
            return trim($matches[1]);
        }
        return '0.0.0';
    }
}
