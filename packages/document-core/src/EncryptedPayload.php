<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class EncryptedPayload
{
    private const VERSION = 1;

    /** @var string */
    private $nonce;
    /** @var string */
    private $ciphertext;
    /** @var string */
    private $tag;

    public function __construct(string $nonce, string $ciphertext, string $tag)
    {
        if (strlen($nonce) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
            throw new InvalidArgumentException('Encrypted payload is invalid.');
        }
        $this->nonce = $nonce;
        $this->ciphertext = $ciphertext;
        $this->tag = $tag;
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || (int) ($data['version'] ?? 0) !== self::VERSION) {
            throw new InvalidArgumentException('Encrypted payload version is invalid.');
        }
        $nonce = base64_decode((string) ($data['nonce'] ?? ''), true);
        $ciphertext = base64_decode((string) ($data['ciphertext'] ?? ''), true);
        $tag = base64_decode((string) ($data['tag'] ?? ''), true);
        if (!is_string($nonce) || !is_string($ciphertext) || !is_string($tag)) {
            throw new InvalidArgumentException('Encrypted payload encoding is invalid.');
        }
        return new self($nonce, $ciphertext, $tag);
    }

    public function toJson(): string
    {
        return json_encode([
            'version' => self::VERSION,
            'nonce' => base64_encode($this->nonce),
            'ciphertext' => base64_encode($this->ciphertext),
            'tag' => base64_encode($this->tag),
        ], JSON_UNESCAPED_SLASHES);
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function ciphertext(): string
    {
        return $this->ciphertext;
    }

    public function tag(): string
    {
        return $this->tag;
    }
}
