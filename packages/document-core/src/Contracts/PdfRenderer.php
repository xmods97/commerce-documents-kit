<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\DocumentSnapshot;

interface PdfRenderer
{
    public function render(DocumentSnapshot $snapshot): string;
}
