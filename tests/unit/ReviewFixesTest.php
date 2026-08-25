<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\AuditEventHash;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\Rendering\BasicPdfRenderer;
use Xmods\CommerceDocuments\Rendering\PdfTextEncoding;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\WooCommerce\OrderData;
use Xmods\CommerceDocuments\WooCommerce\OrderConfirmationPolicy;
use Xmods\CommerceDocuments\WooCommerce\PaidOrderPolicy;
use Xmods\CommerceDocuments\WordPress\AuditChainVerifier;
use Xmods\CommerceDocuments\WordPress\SandboxMailer;

final class ReviewFixesTest extends TestCase
{
    // ---- H2/H3: payment confirmations only, and only when paid ---------------

    public function testUnpaidOrderProducesNoDocument(): void
    {
        $policy = new PaidOrderPolicy();

        self::assertNull($policy->documentTypeFor($this->order('pending', '', 'bacs')));
        self::assertNull($policy->documentTypeFor($this->order('cancelled', '', 'bacs')));
        self::assertNull($policy->documentTypeFor($this->order('failed', '', 'bacs')));
        // Paid status but no gateway payment date: still not paid.
        self::assertNull($policy->documentTypeFor($this->order('processing', '', 'cod')));
    }

    public function testPaidOrderProducesAPaymentConfirmationAndNeverAnInvoice(): void
    {
        $policy = new PaidOrderPolicy();
        $type = $policy->documentTypeFor($this->order('processing', '2026-08-11T10:00:00+00:00', 'stripe'));

        self::assertNotNull($type);
        self::assertSame(DocumentType::PAYMENT_CONFIRMATION, $type->value());
    }

    public function testCashOnDeliveryOnlyCountsWhenTheGatewayIsExplicitlyEnrolled(): void
    {
        $order = $this->order('processing', '', 'cod');

        self::assertNull((new PaidOrderPolicy())->documentTypeFor($order));
        self::assertNull(
            (new PaidOrderPolicy(
                PaidOrderPolicy::DEFAULT_PAID_STATUSES,
                PaidOrderPolicy::COD_POLICY_STATUS_ONLY,
                ['bacs']
            ))->documentTypeFor($order)
        );

        $enrolled = new PaidOrderPolicy(
            PaidOrderPolicy::DEFAULT_PAID_STATUSES,
            PaidOrderPolicy::COD_POLICY_STATUS_ONLY,
            ['cod']
        );
        self::assertNotNull($enrolled->documentTypeFor($order));
        self::assertSame('yes', $enrolled->decision($order)['payment_confirmed']);
        self::assertSame('offline_status_confirmed', $enrolled->decision($order)['payment_status']);
    }

    public function testExplicitOrderRebuildKeepsTheUnpaidConfirmationType(): void
    {
        $policy = new OrderConfirmationPolicy(['pending'], 'order-rebuild-confirmation', 1, true);
        $order = $this->order('completed', '2026-08-11T10:00:00+00:00', 'stripe');

        self::assertSame(
            DocumentType::ORDER_CONFIRMATION,
            $policy->documentTypeFor($order)->value()
        );
        self::assertSame('unpaid', $policy->decision($order)['payment_badge']);
    }

    public function testWooCommercePluginResolvesCoreDocumentTypeDuringRebuild(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/packages/woocommerce/src/Plugin.php');

        self::assertIsString($source);
        self::assertStringContainsString(
            'use Xmods\\CommerceDocuments\\DocumentType;',
            $source
        );
        self::assertStringNotContainsString(
            'use Xmods\\CommerceDocuments\\WooCommerce\\DocumentType;',
            $source
        );
    }

    // ---- H5: audit chain -----------------------------------------------------

    public function testVerifierAcceptsAWellFormedPerDocumentChain(): void
    {
        $key = str_repeat('a', 32);
        $rows = $this->chain($key, 'doc_a', ['document.generated', 'document.sent']);

        $result = AuditChainVerifier::verifyRows('doc_a', $rows, $key);

        self::assertTrue($result['valid']);
        self::assertSame(2, $result['events']);
    }

    public function testVerifierDetectsAnAlteredEventAndABrokenLink(): void
    {
        $key = str_repeat('a', 32);

        $tampered = $this->chain($key, 'doc_a', ['document.generated', 'document.sent']);
        $tampered[1]['event_name'] = 'document.deleted';
        $altered = AuditChainVerifier::verifyRows('doc_a', $tampered, $key);
        self::assertFalse($altered['valid']);
        self::assertSame(2, $altered['broken_at']);

        $orphaned = $this->chain($key, 'doc_a', ['document.generated', 'document.sent']);
        array_shift($orphaned);
        $broken = AuditChainVerifier::verifyRows('doc_a', $orphaned, $key);
        self::assertFalse($broken['valid']);
        self::assertSame(1, $broken['broken_at']);
        self::assertStringContainsString('previous event', $broken['reason']);
    }

    // ---- H4: PDF -------------------------------------------------------------

    public function testPdfPreservesPolishCharactersAndRendersEveryLineItem(): void
    {
        $pdf = (new BasicPdfRenderer(2))->render($this->snapshot('Zażółć gęślą jaźń'));

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
        // Polish glyphs are mapped into the custom encoding, not replaced with '?'.
        self::assertStringContainsString('/Differences [', $pdf);
        self::assertStringContainsString('/aogonek', $pdf);
        self::assertStringNotContainsString('Za???', $pdf);
        // Every line item and the totals block are present.
        self::assertStringContainsString('Blat granitowy', $pdf);
        self::assertStringContainsString('Transport', $pdf);
        self::assertStringContainsString('123,00', $pdf);
    }

    public function testPdfCrossReferenceOffsetsResolveToTheirObjects(): void
    {
        $pdf = (new BasicPdfRenderer(2))->render($this->snapshot('Buyer'));

        preg_match('/xref\s+0 (\d+)\s+(.*?)trailer/s', $pdf, $table);
        preg_match_all('/^(\d{10}) 00000 n $/m', $table[2], $entries);

        self::assertSame((int) $table[1] - 1, count($entries[1]));
        foreach ($entries[1] as $index => $offset) {
            self::assertSame(
                ($index + 1) . ' 0 obj',
                substr($pdf, (int) $offset, strlen(($index + 1) . ' 0 obj'))
            );
        }

        preg_match('/startxref\s+(\d+)/', $pdf, $start);
        self::assertSame('xref', substr($pdf, (int) $start[1], 4));
    }

    public function testPdfTextEncodingNeutralisesStringDelimitersAndControlBytes(): void
    {
        self::assertSame('\\(x\\) \\\\ y', PdfTextEncoding::encode('(x) \\ y'));
        self::assertSame('a b', PdfTextEncoding::encode("a\nb"));
        self::assertStringNotContainsString('?', PdfTextEncoding::encode('ąćęłńóśźż'));
    }

    // ---- M4-M7: sandbox mailer ----------------------------------------------

    public function testSandboxMailerWritesConformantMimeAndNeverOverwrites(): void
    {
        $directory = sys_get_temp_dir() . '/cdk-mail-' . bin2hex(random_bytes(6));
        $mailer = new SandboxMailer($directory, 'shop@example.invalid');
        $snapshot = $this->snapshot('Buyer');

        $mailer->send($snapshot, 'buyer@example.invalid', 'Zamówienie', "Linia 1\nLinia 2", '%PDF-x');
        $mailer->send($snapshot, 'buyer@example.invalid', 'Zamówienie', 'Linia 1', '%PDF-x');

        $files = glob($directory . '/*.eml');
        // Same document, same second: two sends must leave two files.
        self::assertCount(2, $files);

        $eml = (string) file_get_contents($files[0]);
        self::assertStringContainsString('MIME-Version: 1.0', $eml);
        self::assertStringContainsString('From: shop@example.invalid', $eml);
        self::assertStringContainsString('To: buyer@example.invalid', $eml);
        self::assertStringContainsString('Content-Type: multipart/mixed', $eml);
        self::assertStringContainsString('Content-Disposition: attachment', $eml);
        // A non-ASCII subject must be encoded, not emitted raw.
        self::assertStringContainsString('=?UTF-8?B?', $eml);

        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    public function testSandboxMailerAcceptsMultiLineBodiesButRejectsHeaderInjection(): void
    {
        $directory = sys_get_temp_dir() . '/cdk-mail-' . bin2hex(random_bytes(6));
        $mailer = new SandboxMailer($directory);
        $snapshot = $this->snapshot('Buyer');

        // A newline in the body is legitimate and must not be rejected.
        $mailer->send($snapshot, 'buyer@example.invalid', 'Subject', "line 1\nline 2", '%PDF-x');
        self::assertCount(1, glob($directory . '/*.eml'));

        $rejected = 0;
        foreach ([
            ['buyer@example.invalid', "Subject\r\nBcc: evil@example.invalid"],
            ["buyer@example.invalid\r\nBcc: evil@example.invalid", 'Subject'],
        ] as $case) {
            try {
                $mailer->send($snapshot, $case[0], $case[1], 'body', '%PDF-x');
            } catch (\InvalidArgumentException $error) {
                $rejected++;
            }
        }
        self::assertSame(2, $rejected);

        foreach (glob($directory . '/*.eml') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    // ---- helpers -------------------------------------------------------------

    private function order(string $status, string $paidAt, string $method): OrderData
    {
        $address = Address::create('ul. Grzybowska 87', '', '00-844', 'Warszawa', '', 'PL');
        return new OrderData(
            '209',
            $status,
            '2026-08-11T09:00:00+00:00',
            $paidAt,
            Currency::fromCode('PLN'),
            Language::fromTag('pl-PL'),
            Party::create('GEWARD', '', '', $address),
            Party::create('Buyer', '', '', $address),
            [],
            $method
        );
    }

    /** @return array<int, array<string, string>> */
    private function chain(string $key, string $documentId, array $events): array
    {
        $rows = [];
        $previous = '';
        foreach ($events as $index => $event) {
            $createdAt = sprintf('2026-08-11 10:00:%02d', $index);
            $hash = AuditEventHash::next($key, $previous, $event, $documentId, '{}', $createdAt);
            $rows[] = [
                'event_name' => $event,
                'context' => '{}',
                'prev_event_hash' => $previous,
                'event_hash' => $hash,
                'created_at' => $createdAt,
            ];
            $previous = $hash;
        }
        return $rows;
    }

    private function snapshot(string $buyerName): DocumentSnapshot
    {
        $currency = Currency::fromCode('PLN');
        $address = Address::create('ul. Grzybowska 87', '', '00-844', 'Warszawa', '', 'PL');

        return DocumentSnapshot::create(
            'doc_review_fix',
            'ORDER_CONFIRMATION/2026/000001',
            DocumentType::fromString(DocumentType::ORDER_CONFIRMATION),
            DocumentStatus::fromString(DocumentStatus::ISSUED),
            'woocommerce_order',
            '209',
            $currency,
            Language::fromTag('pl-PL'),
            Party::create('GEWARD', '1234567890', 'shop@example.invalid', $address),
            Party::create($buyerName, '', 'buyer@example.invalid', $address),
            [
                DocumentItem::create('Blat granitowy', Quantity::one(), 'szt', Money::fromMinorUnits(10000, $currency), TaxRate::zero()),
                DocumentItem::create('Transport', Quantity::one(), 'usł', Money::fromMinorUnits(2300, $currency), TaxRate::zero()),
            ],
            '2026-08-11T10:00:00+00:00',
            '2026-08-11T10:00:00+00:00',
            1,
            ['payment_method' => 'stripe', 'payment_confirmed' => 'yes', 'order_number' => '209']
        );
    }
}
