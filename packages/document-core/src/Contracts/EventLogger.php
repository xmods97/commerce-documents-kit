<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

interface EventLogger
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function record(string $event, string $documentId, array $context = []): void;
}
