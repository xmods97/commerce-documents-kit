<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\WordPress\Contracts\OptionStore;

final class NativeOptionStore implements OptionStore
{
    public function get(string $key, $default = null)
    {
        if (!function_exists('get_option')) {
            throw new RuntimeException('WordPress options API is unavailable.');
        }
        return get_option($key, $default);
    }

    public function set(string $key, $value): void
    {
        if (!function_exists('update_option')) {
            throw new RuntimeException('WordPress options API is unavailable.');
        }
        update_option($key, $value, false);
    }
}
