<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;
use JsonException;

final class DocumentSnapshot
{
    /** @var array<string, mixed> */
    private $data;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param DocumentItem[] $items
     */
    public static function create(
        string $documentId,
        string $documentNumber,
        DocumentType $type,
        DocumentStatus $status,
        string $sourceType,
        string $sourceId,
        Currency $currency,
        Language $language,
        Party $seller,
        Party $buyer,
        array $items,
        string $createdAt,
        string $issuedAt,
        int $version = 1
    ): self {
        foreach ([$documentId, $documentNumber, $sourceType, $sourceId] as $required) {
            if (trim($required) === '') {
                throw new InvalidArgumentException('Snapshot identifiers cannot be empty.');
            }
        }
        if ($version < 1) {
            throw new InvalidArgumentException('Snapshot version must be positive.');
        }
        self::assertAtomDate($createdAt);
        self::assertAtomDate($issuedAt);

        $serializedItems = [];
        foreach ($items as $item) {
            if (!$item instanceof DocumentItem) {
                throw new InvalidArgumentException('Snapshot accepts DocumentItem values only.');
            }
            if (!$item->unitNet()->currency()->equals($currency)) {
                throw new InvalidArgumentException('Snapshot item currency does not match document currency.');
            }
            $serializedItems[] = self::serializeItem($item);
        }

        $totals = Totals::fromItems($items, $currency);

        return new self([
            'schema_version' => 1,
            'document_id' => trim($documentId),
            'document_number' => trim($documentNumber),
            'document_type' => $type->value(),
            'status' => $status->value(),
            'source_type' => trim($sourceType),
            'source_id' => trim($sourceId),
            'currency' => $currency->code(),
            'language' => $language->tag(),
            'version' => $version,
            'created_at' => $createdAt,
            'issued_at' => $issuedAt,
            'seller' => $seller->toArray(),
            'buyer' => $buyer->toArray(),
            'items' => $serializedItems,
            'totals' => [
                'net' => $totals->net()->minorUnits(),
                'tax' => $totals->tax()->minorUnits(),
                'gross' => $totals->gross()->minorUnits(),
            ],
        ]);
    }

    /**
     * Validates and restores a snapshot through the same domain constructors.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported snapshot schema version.');
        }

        $currency = Currency::fromCode((string) ($data['currency'] ?? ''));
        $items = [];
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Invalid snapshot item.');
            }
            $items[] = DocumentItem::fromSnapshotAmounts(
                (string) ($item['description'] ?? ''),
                Quantity::fromScaledUnits(
                    (int) ($item['quantity']['scaled_units'] ?? 0),
                    (int) ($item['quantity']['scale'] ?? 0)
                ),
                (string) ($item['unit'] ?? ''),
                Money::fromMinorUnits((int) ($item['unit_net'] ?? 0), $currency),
                TaxRate::fromPartsPerMillion((int) ($item['tax_rate_ppm'] ?? 0)),
                Money::fromMinorUnits((int) ($item['net'] ?? 0), $currency),
                Money::fromMinorUnits((int) ($item['tax'] ?? 0), $currency)
            );
        }

        return self::create(
            (string) ($data['document_id'] ?? ''),
            (string) ($data['document_number'] ?? ''),
            DocumentType::fromString((string) ($data['document_type'] ?? '')),
            DocumentStatus::fromString((string) ($data['status'] ?? '')),
            (string) ($data['source_type'] ?? ''),
            (string) ($data['source_id'] ?? ''),
            $currency,
            Language::fromTag((string) ($data['language'] ?? '')),
            self::partyFromArray((array) ($data['seller'] ?? [])),
            self::partyFromArray((array) ($data['buyer'] ?? [])),
            $items,
            (string) ($data['created_at'] ?? ''),
            (string) ($data['issued_at'] ?? ''),
            (int) ($data['version'] ?? 0)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode(
            $this->data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public function contentHash(): string
    {
        return hash('sha256', $this->toJson());
    }

    private static function serializeItem(DocumentItem $item): array
    {
        return [
            'description' => $item->description(),
            'quantity' => [
                'scaled_units' => $item->quantity()->scaledUnits(),
                'scale' => $item->quantity()->scale(),
            ],
            'unit' => $item->unit(),
            'unit_net' => $item->unitNet()->minorUnits(),
            'tax_rate_ppm' => $item->taxRate()->partsPerMillion(),
            'net' => $item->net()->minorUnits(),
            'tax' => $item->tax()->minorUnits(),
            'gross' => $item->gross()->minorUnits(),
        ];
    }

    private static function partyFromArray(array $data): Party
    {
        $address = (array) ($data['address'] ?? []);
        return Party::create(
            (string) ($data['name'] ?? ''),
            (string) ($data['tax_identifier'] ?? ''),
            (string) ($data['email'] ?? ''),
            Address::create(
                (string) ($address['line1'] ?? ''),
                (string) ($address['line2'] ?? ''),
                (string) ($address['postal_code'] ?? ''),
                (string) ($address['city'] ?? ''),
                (string) ($address['region'] ?? ''),
                (string) ($address['country_code'] ?? '')
            )
        );
    }

    private static function assertAtomDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat(DATE_ATOM, $date);
        if ($parsed === false || $parsed->format(DATE_ATOM) !== $date) {
            throw new InvalidArgumentException('Snapshot dates must use DATE_ATOM format.');
        }
    }
}
