<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Xmods\\CommerceDocuments\\WooCommerce\\' => __DIR__ . '/../packages/woocommerce/src/',
        'Xmods\\CommerceDocuments\\WordPress\\' => __DIR__ . '/../packages/wordpress/src/',
        'Xmods\\CommerceDocuments\\' => __DIR__ . '/../packages/document-core/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (strpos($class, $prefix) !== 0) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $file = $directory . $relative;
        if (is_readable($file)) {
            require $file;
        }
        return;
    }
});
