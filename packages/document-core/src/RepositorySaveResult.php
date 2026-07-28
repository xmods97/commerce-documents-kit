<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

final class RepositorySaveResult
{
    /** @var DocumentSnapshot */
    private $snapshot;
    /** @var bool */
    private $created;

    private function __construct(DocumentSnapshot $snapshot, bool $created)
    {
        $this->snapshot = $snapshot;
        $this->created = $created;
    }

    public static function created(DocumentSnapshot $snapshot): self
    {
        return new self($snapshot, true);
    }

    public static function existing(DocumentSnapshot $snapshot): self
    {
        return new self($snapshot, false);
    }

    public function snapshot(): DocumentSnapshot
    {
        return $this->snapshot;
    }

    public function wasCreated(): bool
    {
        return $this->created;
    }
}
