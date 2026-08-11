<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\Contracts\Cipher;
use Xmods\CommerceDocuments\EncryptedPayload;

final class OpenSslAesGcmCipher implements Cipher
{
    private const METHOD = 'aes-256-gcm';

    /** @var string */
    private $key;

    public function __construct(string $key)
    {
        if (strlen($key) !== 32) {
            throw new RuntimeException('AES-256-GCM requires a 32-byte key.');
        }
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('OpenSSL is required for encrypted document storage.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext, string $associatedData): EncryptedPayload
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::METHOD,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData,
            16
        );
        if (!is_string($ciphertext)) {
            throw new RuntimeException('Document encryption failed.');
        }
        return new EncryptedPayload($nonce, $ciphertext, $tag);
    }

    public function decrypt(EncryptedPayload $payload, string $associatedData): string
    {
        $plaintext = openssl_decrypt(
            $payload->ciphertext(),
            self::METHOD,
            $this->key,
            OPENSSL_RAW_DATA,
            $payload->nonce(),
            $payload->tag(),
            $associatedData
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('Document decryption or integrity verification failed.');
        }
        return $plaintext;
    }
}
