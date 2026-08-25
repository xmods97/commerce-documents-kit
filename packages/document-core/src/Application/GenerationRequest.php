<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Application;

use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Party;

final class GenerationRequest
{
    /** @var DocumentType */
    public $type;
    /** @var DocumentStatus */
    public $status;
    /** @var string */
    public $sourceType;
    /** @var string */
    public $sourceId;
    /** @var string */
    public $policy;
    /** @var int */
    public $version;
    /** @var Currency */
    public $currency;
    /** @var Language */
    public $language;
    /** @var Party */
    public $seller;
    /** @var Party */
    public $buyer;
    /** @var array */
    public $items;
    /** @var string */
    public $createdAt;
    /** @var string */
    public $issuedAt;
    /** @var array<string, scalar|null> */
    public $metadata;
    /** @var string|null */
    public $idempotencySourceType;
    /** @var string|null */
    public $idempotencySourceId;

    public function __construct(
        DocumentType $type,
        DocumentStatus $status,
        string $sourceType,
        string $sourceId,
        string $policy,
        int $version,
        Currency $currency,
        Language $language,
        Party $seller,
        Party $buyer,
        array $items,
        string $createdAt,
        string $issuedAt,
        array $metadata = []
    ) {
        $this->type = $type;
        $this->status = $status;
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->policy = $policy;
        $this->version = $version;
        $this->currency = $currency;
        $this->language = $language;
        $this->seller = $seller;
        $this->buyer = $buyer;
        $this->items = $items;
        $this->createdAt = $createdAt;
        $this->issuedAt = $issuedAt;
        $this->metadata = $metadata;
        $this->idempotencySourceType = null;
        $this->idempotencySourceId = null;
    }

    public function useIdempotencySource(string $sourceType, string $sourceId): void
    {
        if (trim($sourceType) === '' || trim($sourceId) === '') {
            throw new \InvalidArgumentException('Idempotency source is required.');
        }
        $this->idempotencySourceType = trim($sourceType);
        $this->idempotencySourceId = trim($sourceId);
    }
}
