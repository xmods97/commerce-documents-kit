<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class Address
{
    /** @var string */
    private $line1;
    /** @var string */
    private $line2;
    /** @var string */
    private $postalCode;
    /** @var string */
    private $city;
    /** @var string */
    private $region;
    /** @var string */
    private $countryCode;

    private function __construct(
        string $line1,
        string $line2,
        string $postalCode,
        string $city,
        string $region,
        string $countryCode
    ) {
        $line1 = trim($line1);
        $city = trim($city);

        if ($line1 === '' || $city === '') {
            throw new InvalidArgumentException('Address line 1 and city are required.');
        }
        if (!preg_match('/^[A-Z]{2}$/D', $countryCode)) {
            throw new InvalidArgumentException('Country must be a two-letter uppercase code.');
        }

        $this->line1 = $line1;
        $this->line2 = trim($line2);
        $this->postalCode = trim($postalCode);
        $this->city = $city;
        $this->region = trim($region);
        $this->countryCode = $countryCode;
    }

    public static function create(
        string $line1,
        string $line2,
        string $postalCode,
        string $city,
        string $region,
        string $countryCode
    ): self {
        return new self($line1, $line2, $postalCode, $city, $region, $countryCode);
    }

    public function toArray(): array
    {
        return [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'region' => $this->region,
            'country_code' => $this->countryCode,
        ];
    }
}
