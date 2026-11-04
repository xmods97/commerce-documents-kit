<?php

declare(strict_types=1);

// Read-only local-runtime check. It loads the local WordPress and never writes
// options, posts, files, mail or database rows.
define('WP_USE_THEMES', false);
require 'D:/laragon/www/geward/wp-load.php';

$root = dirname(__DIR__, 2);
require $root . '/tools/runtime-autoload.php';

use Xmods\CommerceDocuments\WordPress\NativeMediaLibrary;
use Xmods\CommerceDocuments\WordPress\WordPressLogoProvider;

$media = new NativeMediaLibrary();
$themeId = $media->themeHeaderLogoAttachmentId();
$provider = new WordPressLogoProvider($media);
$logo = $provider->logo();

if ($themeId !== 130) {
    throw new RuntimeException('Expected the current local Divi header logo attachment 130. Got ' . $themeId . '.');
}
if ($logo === null || $logo->width() !== 480 || $logo->height() !== 199) {
    throw new RuntimeException(
        'The local Divi logo did not resolve to the expected proportional full lockup: '
        . ($logo === null ? 'null (' . $provider->lastRejection() . ')' : $logo->width() . 'x' . $logo->height())
    );
}

echo 'PASS: Divi header attachment=' . $themeId . ' dimensions=' . $logo->width() . 'x' . $logo->height() . PHP_EOL;
