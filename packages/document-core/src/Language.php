<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class Language
{
    /** @var string */
    private $tag;

    private function __construct(string $tag)
    {
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $tag)) {
            throw new InvalidArgumentException('Language must be a normalized language tag.');
        }
        $this->tag = $tag;
    }

    public static function fromTag(string $tag): self
    {
        return new self($tag);
    }

    public function tag(): string
    {
        return $this->tag;
    }
}
