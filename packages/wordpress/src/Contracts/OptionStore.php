<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress\Contracts;

interface OptionStore
{
    /**
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * @param mixed $value
     */
    public function set(string $key, $value): void;
}
