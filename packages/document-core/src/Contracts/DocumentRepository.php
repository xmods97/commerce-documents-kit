<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\IdempotencyKey;

interface DocumentRepository
{
    public function findByIdempotencyKey(IdempotencyKey $key): ?DocumentSnapshot;

    public function save(IdempotencyKey $key, DocumentSnapshot $snapshot): void;
}
