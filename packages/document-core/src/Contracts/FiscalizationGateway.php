<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\DocumentSnapshot;

interface FiscalizationGateway
{
    /**
     * Returns an external immutable reference only after confirmed acceptance.
     */
    public function submit(DocumentSnapshot $snapshot): string;
}
