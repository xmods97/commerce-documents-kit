<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\DocumentType;

interface NumberGenerator
{
    public function next(DocumentType $type, string $issuedAt): string;
}
