<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Application\GenerateDocument;
use Xmods\CommerceDocuments\Application\GenerationRequest;
use Xmods\CommerceDocuments\Contracts\DocumentRepository;
use Xmods\CommerceDocuments\Contracts\EventLogger;
use Xmods\CommerceDocuments\Contracts\NumberGenerator;
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
use Xmods\CommerceDocuments\RepositorySaveResult;
use Xmods\CommerceDocuments\TaxRate;

final class GenerateDocumentTest extends TestCase
{
    public function testRepeatedHookReturnsExistingSnapshotWithoutDuplicate(): void
    {
        $repository = new MemoryRepository();
        $numbers = new SequentialNumbers();
        $events = new MemoryEvents();
        $service = new GenerateDocument($repository, $numbers, $events);
        $request = $this->request('Original Buyer');

        $first = $service->execute($request);
        $second = $service->execute($request);

        self::assertSame($first, $second);
        self::assertSame(1, $repository->saveCount);
        self::assertSame(1, $numbers->calls);
        self::assertCount(1, $events->events);
    }

    public function testChangedOrderDoesNotMutateExistingSnapshotForSameKey(): void
    {
        $service = new GenerateDocument(
            new MemoryRepository(),
            new SequentialNumbers(),
            new MemoryEvents()
        );

        $original = $service->execute($this->request('Original Buyer'));
        $replayed = $service->execute($this->request('Changed Buyer'));

        self::assertSame($original, $replayed);
        self::assertSame('Original Buyer', $original->toArray()['buyer']['name']);
    }

    public function testAtomicSavePreventsDuplicateDuringConcurrentRace(): void
    {
        $repository = new RacingRepository();
        $numbers = new SequentialNumbers();
        $events = new MemoryEvents();
        $service = new GenerateDocument($repository, $numbers, $events);

        $first = $service->execute($this->request('Original Buyer'));
        $second = $service->execute($this->request('Original Buyer'));

        self::assertSame($first, $second);
        self::assertSame(1, $repository->createdCount);
        self::assertSame(2, $numbers->calls);
        self::assertCount(1, $events->events);
    }

    private function request(string $buyerName): GenerationRequest
    {
        $currency = Currency::fromCode('EUR');
        $address = Address::create('1 Test Street', '', '00-001', 'Test City', '', 'PL');

        return new GenerationRequest(
            DocumentType::fromString(DocumentType::INVOICE),
            DocumentStatus::fromString(DocumentStatus::ISSUED),
            'woocommerce_order',
            '42',
            'test-policy',
            1,
            $currency,
            Language::fromTag('en'),
            Party::create('Seller', 'SELLER', '', $address),
            Party::create($buyerName, 'BUYER', '', $address),
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

final class MemoryRepository implements DocumentRepository
{
    /** @var array<string, DocumentSnapshot> */
    private $documents = [];
    /** @var int */
    public $saveCount = 0;

    public function findByIdempotencyKey(IdempotencyKey $key): ?DocumentSnapshot
    {
        return $this->documents[$key->value()] ?? null;
    }

    public function save(
        IdempotencyKey $key,
        DocumentSnapshot $snapshot
    ): RepositorySaveResult
    {
        if (isset($this->documents[$key->value()])) {
            return RepositorySaveResult::existing($this->documents[$key->value()]);
        }
        $this->documents[$key->value()] = $snapshot;
        ++$this->saveCount;
        return RepositorySaveResult::created($snapshot);
    }
}

final class SequentialNumbers implements NumberGenerator
{
    /** @var int */
    public $calls = 0;

    public function next(DocumentType $type, string $issuedAt): string
    {
        ++$this->calls;
        return strtoupper($type->value()) . '/' . $this->calls;
    }
}

final class MemoryEvents implements EventLogger
{
    /** @var array<int, array<string, mixed>> */
    public $events = [];

    public function record(string $event, string $documentId, array $context = []): void
    {
        $this->events[] = compact('event', 'documentId', 'context');
    }
}

final class RacingRepository implements DocumentRepository
{
    /** @var DocumentSnapshot|null */
    private $stored;
    /** @var int */
    public $createdCount = 0;

    public function findByIdempotencyKey(IdempotencyKey $key): ?DocumentSnapshot
    {
        // Simulates two workers that both miss the optimistic pre-check.
        return null;
    }

    public function save(
        IdempotencyKey $key,
        DocumentSnapshot $snapshot
    ): RepositorySaveResult {
        if ($this->stored !== null) {
            return RepositorySaveResult::existing($this->stored);
        }
        $this->stored = $snapshot;
        ++$this->createdCount;
        return RepositorySaveResult::created($snapshot);
    }
}
