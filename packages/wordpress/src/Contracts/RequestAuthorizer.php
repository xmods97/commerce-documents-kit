<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress\Contracts;

interface RequestAuthorizer
{
    public function assertCapability(string $capability): void;

    public function assertNonce(string $nonce, string $action): void;
}
