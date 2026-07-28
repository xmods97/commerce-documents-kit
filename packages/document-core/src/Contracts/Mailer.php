<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\DocumentSnapshot;

interface Mailer
{
    public function send(
        DocumentSnapshot $snapshot,
        string $recipient,
        string $subject,
        string $message,
        string $pdfBinary
    ): void;
}
