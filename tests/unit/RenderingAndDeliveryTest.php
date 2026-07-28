<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Application\DeliverDocument;
use Xmods\CommerceDocuments\Contracts\EventLogger;
use Xmods\CommerceDocuments\Contracts\Mailer;
use Xmods\CommerceDocuments\Contracts\PdfRenderer;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\Rendering\HtmlRenderer;
use Xmods\CommerceDocuments\Rendering\TemplateCatalog;
use Xmods\CommerceDocuments\TaxRate;

final class RenderingAndDeliveryTest extends TestCase
{
    public function testRendersPolishAndEnglishLabelsAndEscapesContent(): void
    {
        $factory = new SnapshotFixtureFactory();
        $renderer = new HtmlRenderer(new TemplateCatalog());

        $english = $renderer->render($factory->create('en', '<script>'));
        $polish = $renderer->render($factory->create('pl-PL', 'Buyer'));

        self::assertStringContainsString('Seller', $english);
        self::assertStringContainsString('&lt;script&gt;', $english);
        self::assertStringNotContainsString('<script>', $english);
        self::assertStringContainsString('Sprzedawca', $polish);
        self::assertStringContainsString('Brutto', $polish);
    }

    public function testDeliveryRendersSendsAndLogsWithoutStoringRecipient(): void
    {
        $pdf = new TestPdfRenderer();
        $mailer = new TestMailer();
        $events = new DeliveryEvents();
        $snapshot = (new SnapshotFixtureFactory())->create('en', 'Buyer');

        (new DeliverDocument($pdf, $mailer, $events))
            ->execute($snapshot, 'buyer@example.invalid', 'Subject', 'Message');

        self::assertSame('%PDF-test', $mailer->binary);
        self::assertSame('buyer@example.invalid', $mailer->recipient);
        self::assertSame('document.sent', $events->last['event']);
        self::assertArrayHasKey('recipient_hash', $events->last['context']);
        self::assertStringNotContainsString('buyer@example.invalid', json_encode($events->last));
    }
}

final class TestPdfRenderer implements PdfRenderer
{
    public function render(DocumentSnapshot $snapshot): string
    {
        return '%PDF-test';
    }
}

final class TestMailer implements Mailer
{
    public $binary = '';
    public $recipient = '';

    public function send(
        DocumentSnapshot $snapshot,
        string $recipient,
        string $subject,
        string $message,
        string $pdfBinary
    ): void {
        $this->recipient = $recipient;
        $this->binary = $pdfBinary;
    }
}

final class DeliveryEvents implements EventLogger
{
    public $last = [];

    public function record(string $event, string $documentId, array $context = []): void
    {
        $this->last = compact('event', 'documentId', 'context');
    }
}

final class SnapshotFixtureFactory
{
    public function create(string $language, string $buyerName): DocumentSnapshot
    {
        $currency = Currency::fromCode('EUR');
        $address = Address::create('1 Test Street', '', '00-001', 'Test City', '', 'PL');

        return DocumentSnapshot::create(
            'doc_fixture',
            'TEST/1',
            DocumentType::fromString(DocumentType::INVOICE),
            DocumentStatus::fromString(DocumentStatus::ISSUED),
            'test',
            '1',
            $currency,
            Language::fromTag($language),
            Party::create('Seller', '', '', $address),
            Party::create($buyerName, '', '', $address),
            [
                DocumentItem::create(
                    'Item',
                    Quantity::one(),
                    'unit',
                    Money::fromMinorUnits(1000, $currency),
                    TaxRate::zero()
                ),
            ],
            '2026-07-28T10:00:00+00:00',
            '2026-07-28T10:00:00+00:00'
        );
    }
}
