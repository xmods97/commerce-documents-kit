<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\EncryptedPayload;

interface Cipher
{
    public function encrypt(string $plaintext, string $associatedData): EncryptedPayload;

    public function decrypt(EncryptedPayload $payload, string $associatedData): string;
}
