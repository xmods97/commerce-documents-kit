<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Party;

final class DocumentMetadataTest extends TestCase
{
    public function testSnapshotsPartyAndAddress(): void
    {
        $address = Address::create('1 Example Street', '', '00-001', 'Example City', '', 'PL');
        $party = Party::create('Example Company', 'TEST-ID', 'test@example.invalid', $address);

        self::assertSame('Example Company', $party->toArray()['name']);
        self::assertSame('PL', $party->toArray()['address']['country_code']);
    }

    public function testCreatesNeutralDocumentMetadata(): void
    {
        self::assertSame('proforma', DocumentType::fromString('proforma')->value());
        self::assertSame('issued', DocumentStatus::fromString('issued')->value());
        self::assertSame('pl-PL', Language::fromTag('pl-PL')->tag());
        self::assertSame('en', Language::fromTag('en')->tag());
    }

    /**
     * @dataProvider invalidMetadata
     */
    public function testRejectsInvalidMetadata(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $factory();
    }

    public function invalidMetadata(): array
    {
        return [
            'country' => [static function (): void {
                Address::create('Street', '', '', 'City', '', 'pol');
            }],
            'party name' => [static function (): void {
                Party::create('', '', '', Address::create('Street', '', '', 'City', '', 'PL'));
            }],
            'email' => [static function (): void {
                Party::create(
                    'Company',
                    '',
                    'invalid',
                    Address::create('Street', '', '', 'City', '', 'PL')
                );
            }],
            'document type' => [static function (): void {
                DocumentType::fromString('unknown');
            }],
            'status' => [static function (): void {
                DocumentStatus::fromString('unknown');
            }],
            'language' => [static function (): void {
                Language::fromTag('PL_pl');
            }],
        ];
    }
}
