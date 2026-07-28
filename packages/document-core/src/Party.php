<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class Party
{
    /** @var string */
    private $name;
    /** @var string */
    private $taxIdentifier;
    /** @var string */
    private $email;
    /** @var Address */
    private $address;

    private function __construct(
        string $name,
        string $taxIdentifier,
        string $email,
        Address $address
    ) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Party name is required.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Party email is invalid.');
        }

        $this->name = $name;
        $this->taxIdentifier = trim($taxIdentifier);
        $this->email = $email;
        $this->address = $address;
    }

    public static function create(
        string $name,
        string $taxIdentifier,
        string $email,
        Address $address
    ): self {
        return new self($name, $taxIdentifier, trim($email), $address);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tax_identifier' => $this->taxIdentifier,
            'email' => $this->email,
            'address' => $this->address->toArray(),
        ];
    }
}
