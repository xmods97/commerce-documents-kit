<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class Currency
{
    /** @var string */
    private $code;

    private function __construct(string $code)
    {
        if (!preg_match('/^[A-Z]{3}$/D', $code)) {
            throw new InvalidArgumentException('Currency must be a three-letter uppercase code.');
        }

        $this->code = $code;
    }

    public static function fromCode(string $code): self
    {
        return new self($code);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
