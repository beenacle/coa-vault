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
        /** The certificate's stated limit for this test, e.g. ">98%" or "<5 EU/vial". */
        public string $spec_text = '',
        /** Pass/fail against that spec; null when the certificate states no verdict. */
        public ?bool $passed = null,
        public int $position = 0,
    ) {
    }
}
