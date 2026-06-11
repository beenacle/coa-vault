<?php

declare(strict_types=1);

namespace CoaVault\Data;

/**
 * One normalized measurement row (purity / mass / arbitrary name-value-unit).
 */
final class Characteristic
{
    public function __construct(
        public string $name_slug,
        public string $name_label = '',
        public ?float $value_num = null,
        public string $value_text = '',
        public string $unit = '',
        public int $position = 0,
    ) {
    }
}
