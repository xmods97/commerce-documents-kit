<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\IdempotencyKey;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\WordPress\SchemaDefinition;
use Xmods\CommerceDocuments\WordPress\EncryptedSnapshotCodec;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;
use Xmods\CommerceDocuments\WordPress\WpdbDocumentRepository;

final class WpdbPersistenceTest extends TestCase
{
    public function testSchemaHasAtomicIdempotencyAndSequenceKeys(): void
    {
        $sql = implode("\n", SchemaDefinition::sql('wp_'));

        self::assertStringContainsString('UNIQUE KEY idempotency_key', $sql);
        self::assertStringContainsString('PRIMARY KEY (series_key)', $sql);
        self::assertStringContainsString('commerce_document_events', $sql);
        self::assertStringContainsString('commerce_document_links', $sql);
        self::assertStringContainsString('commerce_document_deliveries', $sql);
        self::assertStringContainsString('recipient_hmac char(64)', $sql);
        self::assertStringContainsString('commerce_document_migrations', $sql);
        self::assertStringContainsString('snapshot_cipher longtext NULL', $sql);
        self::assertStringContainsString('encryption_version smallint unsigned NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('prev_event_hash char(64)', $sql);
    }

    public function testRepositoryReturnsExistingSnapshotOnDuplicateInsert(): void
    {
        $wpdb = new FakeWpdb();
        $repository = new WpdbDocumentRepository(
            $wpdb,
            'wp_commerce_documents',
            new EncryptedSnapshotCodec(new OpenSslAesGcmCipher(str_repeat('k', 32)))
        );
        $key = IdempotencyKey::forSource(
            'woocommerce_order',
            '42',
            DocumentType::fromString(DocumentType::INVOICE),
            'policy',
            1
        );
        $snapshot = $this->snapshot();

        $created = $repository->save($key, $snapshot);
        $duplicate = $repository->save($key, $this->snapshot());

        self::assertTrue($created->wasCreated());
        self::assertFalse($duplicate->wasCreated());
        self::assertSame($snapshot->contentHash(), $duplicate->snapshot()->contentHash());
        self::assertCount(1, $wpdb->documents);
    }

    public function testNumberGeneratorUsesConnectionLocalAtomicSequence(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/packages/wordpress/src/WpdbNumberGenerator.php'
        );
        self::assertStringContainsString('LAST_INSERT_ID(current_value + 1)', $source);
        self::assertStringContainsString("SELECT LAST_INSERT_ID()", $source);
    }

    private function snapshot(): DocumentSnapshot
    {
        $currency = Currency::fromCode('EUR');
        $address = Address::create('1 Test Street', '', '00-001', 'Test City', '', 'PL');
        return DocumentSnapshot::create(
            'doc_test',
            'INVOICE/2026/000001',
            DocumentType::fromString(DocumentType::INVOICE),
            DocumentStatus::fromString(DocumentStatus::ISSUED),
            'woocommerce_order',
            '42',
            $currency,
            Language::fromTag('en'),
            Party::create('Seller', '', '', $address),
            Party::create('Buyer', '', '', $address),
            [
                DocumentItem::create(
                    'Item',
                    Quantity::one(),
                    'unit',
                    Money::fromMinorUnits(100, $currency),
                    TaxRate::zero()
                ),
            ],
            '2026-07-28T10:00:00+00:00',
            '2026-07-28T10:00:00+00:00'
        );
    }
}

final class FakeWpdb
{
    public $documents = [];
    private $preparedKey = '';

    public function prepare(string $sql, string $value): string
    {
        $this->preparedKey = $value;
        return $sql;
    }

    public function get_var(string $sql)
    {
        return $this->documents[$this->preparedKey]['snapshot'] ?? null;
    }

    public function get_row(string $sql, $output)
    {
        return $this->documents[$this->preparedKey] ?? null;
    }

    public function insert(string $table, array $data, array $formats)
    {
        $key = $data['idempotency_key'];
        if (isset($this->documents[$key])) {
            return false;
        }
        $this->documents[$key] = $data;
        return 1;
    }
}
