<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\IdempotencyKey;
use Xmods\CommerceDocuments\RepositorySaveResult;

interface DocumentRepository
{
    public function findByIdempotencyKey(IdempotencyKey $key): ?DocumentSnapshot;

    /**
     * Atomically inserts by idempotency key or returns the existing snapshot.
     */
    public function save(IdempotencyKey $key, DocumentSnapshot $snapshot): RepositorySaveResult;
}
