<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php verify-plugin-bootstrap.php <plugin-entry>\n");
    exit(2);
}

$entry = $argv[1];
if (!is_file($entry)) {
    fwrite(STDERR, "Plugin entry does not exist: {$entry}\n");
    exit(2);
}

define('ABSPATH', __DIR__);

final class WooCommerce
{
}

$GLOBALS['cdk_actions'] = [];
$GLOBALS['cdk_activation_hooks'] = [];

function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['cdk_actions'][$hook][] = [$callback, $priority, $acceptedArgs];
}

function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['cdk_filters'][$hook][] = [$callback, $priority, $acceptedArgs];
}

function register_activation_hook(string $file, $callback): void
{
    $GLOBALS['cdk_activation_hooks'][] = [$file, $callback];
}

function is_admin(): bool
{
    return false;
}

require $entry;

if (!class_exists(\Xmods\CommerceDocuments\WooCommerce\Plugin::class)) {
    throw new RuntimeException('Packaged runtime autoloader did not load the WooCommerce plugin class.');
}

if (!class_exists(\Xmods\CommerceDocuments\WordPress\Installer::class)) {
    throw new RuntimeException('Packaged runtime autoloader did not load the installer.');
}

if (!class_exists(\Xmods\CommerceDocuments\WooCommerce\AdminController::class)
    || !class_exists(\Xmods\CommerceDocuments\WooCommerce\AdminSettings::class)
) {
    throw new RuntimeException('Packaged runtime autoloader did not load the admin runtime.');
}

if (!isset($GLOBALS['cdk_actions']['plugins_loaded'][0][0])
    || !is_callable($GLOBALS['cdk_actions']['plugins_loaded'][0][0])
) {
    throw new RuntimeException('Deferred plugin bootstrap was not registered.');
}

if (count($GLOBALS['cdk_activation_hooks']) !== 1) {
    throw new RuntimeException('The database installer activation hook was not registered exactly once.');
}

$GLOBALS['cdk_actions']['plugins_loaded'][0][0]();

if (!isset($GLOBALS['cdk_actions']['woocommerce_order_status_changed'])) {
    throw new RuntimeException('WooCommerce order status hook was not registered after plugins_loaded.');
}

fwrite(STDOUT, "Packaged plugin bootstrap verified.\n");
