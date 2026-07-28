<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\Contracts\NumberGenerator;
use Xmods\CommerceDocuments\DocumentType;

final class WpdbNumberGenerator implements NumberGenerator
{
    /** @var object */
    private $wpdb;
    /** @var string */
    private $table;

    public function __construct($wpdb, string $table)
    {
        $this->wpdb = $wpdb;
        $this->table = $table;
    }

    public function next(DocumentType $type, string $issuedAt): string
    {
        $date = new \DateTimeImmutable($issuedAt);
        $year = $date->format('Y');
        $series = $type->value() . ':' . $year;
        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->table} (series_key, current_value)
             VALUES (%s, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)",
            $series
        );
        if ($this->wpdb->query($sql) === false) {
            throw new RuntimeException('Document number allocation failed.');
        }
        $number = (int) $this->wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($number < 1) {
            throw new RuntimeException('Document sequence returned an invalid value.');
        }

        return strtoupper($type->value()) . '/' . $year . '/' . str_pad(
            (string) $number,
            6,
            '0',
            STR_PAD_LEFT
        );
    }
}
