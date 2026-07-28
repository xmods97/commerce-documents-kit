<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\WordPress\Contracts\RequestAuthorizer;

final class NativeRequestAuthorizer implements RequestAuthorizer
{
    public function assertCapability(string $capability): void
    {
        if (!function_exists('current_user_can') || !current_user_can($capability)) {
            throw new RuntimeException('The current user is not authorized.');
        }
    }

    public function assertNonce(string $nonce, string $action): void
    {
        if (!function_exists('wp_verify_nonce') || wp_verify_nonce($nonce, $action) === false) {
            throw new RuntimeException('Request nonce verification failed.');
        }
    }
}
