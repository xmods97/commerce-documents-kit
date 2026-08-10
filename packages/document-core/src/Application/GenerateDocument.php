<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Application;

use Xmods\CommerceDocuments\Contracts\DocumentRepository;
use Xmods\CommerceDocuments\Contracts\EventLogger;
use Xmods\CommerceDocuments\Contracts\NumberGenerator;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\IdempotencyKey;

final class GenerateDocument
{
    /** @var DocumentRepository */
    private $repository;
    /** @var NumberGenerator */
    private $numbers;
    /** @var EventLogger */
    private $events;

    public function __construct(
        DocumentRepository $repository,
        NumberGenerator $numbers,
        EventLogger $events
    ) {
        $this->repository = $repository;
        $this->numbers = $numbers;
        $this->events = $events;
    }

    public function execute(GenerationRequest $request): DocumentSnapshot
    {
        $key = IdempotencyKey::forSource(
            $request->sourceType,
            $request->sourceId,
            $request->type
        );
        $existing = $this->repository->findByIdempotencyKey($key);

        if ($existing !== null) {
            return $existing;
        }

        $snapshot = DocumentSnapshot::create(
            'doc_' . substr($key->value(), 0, 24),
            $this->numbers->next($request->type, $request->issuedAt),
            $request->type,
            $request->status,
            $request->sourceType,
            $request->sourceId,
            $request->currency,
            $request->language,
            $request->seller,
            $request->buyer,
            $request->items,
            $request->createdAt,
            $request->issuedAt,
            $request->version
        );

        $result = $this->repository->save($key, $snapshot);
        if ($result->wasCreated()) {
            $this->events->record(
                'document.generated',
                $snapshot->toArray()['document_id'],
                [
                    'source_type' => $request->sourceType,
                    'source_id' => $request->sourceId,
                    'document_type' => $request->type->value(),
                ]
            );
        }

        return $result->snapshot();
    }
}
