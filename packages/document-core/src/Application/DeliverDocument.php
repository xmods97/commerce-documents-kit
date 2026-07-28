<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Application;

use InvalidArgumentException;
use Xmods\CommerceDocuments\Contracts\EventLogger;
use Xmods\CommerceDocuments\Contracts\Mailer;
use Xmods\CommerceDocuments\Contracts\PdfRenderer;
use Xmods\CommerceDocuments\DocumentSnapshot;

final class DeliverDocument
{
    /** @var PdfRenderer */
    private $pdf;
    /** @var Mailer */
    private $mailer;
    /** @var EventLogger */
    private $events;

    public function __construct(PdfRenderer $pdf, Mailer $mailer, EventLogger $events)
    {
        $this->pdf = $pdf;
        $this->mailer = $mailer;
        $this->events = $events;
    }

    public function execute(
        DocumentSnapshot $snapshot,
        string $recipient,
        string $subject,
        string $message
    ): void {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Delivery recipient is invalid.');
        }
        $binary = $this->pdf->render($snapshot);
        $this->mailer->send($snapshot, $recipient, $subject, $message, $binary);
        $this->events->record(
            'document.sent',
            $snapshot->toArray()['document_id'],
            ['recipient_hash' => hash('sha256', strtolower($recipient))]
        );
    }
}
