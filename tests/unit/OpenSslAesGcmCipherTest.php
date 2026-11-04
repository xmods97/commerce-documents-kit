<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;

final class OpenSslAesGcmCipherTest extends TestCase
{
    public function testRoundTripRequiresMatchingAssociatedData(): void
    {
        $cipher = new OpenSslAesGcmCipher(str_repeat('k', 32));
        $payload = $cipher->encrypt('sensitive snapshot', 'document:doc_1');

        self::assertSame('sensitive snapshot', $cipher->decrypt($payload, 'document:doc_1'));

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($payload, 'document:doc_2');
    }
}
