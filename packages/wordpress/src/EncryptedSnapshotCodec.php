<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\Contracts\Cipher;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\EncryptedPayload;

final class EncryptedSnapshotCodec
{
    /** @var Cipher */
    private $cipher;

    public function __construct(Cipher $cipher)
    {
        $this->cipher = $cipher;
    }

    public function encrypt(DocumentSnapshot $snapshot): string
    {
        $documentId = (string) $snapshot->toArray()['document_id'];
        return $this->cipher->encrypt($snapshot->toJson(), $documentId)->toJson();
    }

    public function decrypt(string $payload, string $documentId): DocumentSnapshot
    {
        $json = $this->cipher->decrypt(EncryptedPayload::fromJson($payload), $documentId);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Encrypted document snapshot is invalid.');
        }
        $snapshot = DocumentSnapshot::fromArray($data);
        if ((string) $snapshot->toArray()['document_id'] !== $documentId) {
            throw new RuntimeException('Encrypted document snapshot identity is invalid.');
        }
        return $snapshot;
    }
}
