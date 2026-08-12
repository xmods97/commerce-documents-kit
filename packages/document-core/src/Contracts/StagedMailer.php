<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Contracts;

use Xmods\CommerceDocuments\DocumentSnapshot;

interface StagedMailer extends Mailer
{
    public function stage(
        DocumentSnapshot $snapshot,
        string $recipient,
        string $subject,
        string $message,
        string $pdfBinary
    ): string;

    public function commit(string $artifact): string;

    public function discard(string $artifact): void;
}
