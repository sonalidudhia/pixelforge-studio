<?php

namespace App\Pixelforge;

use App\Support\Money;

/**
 * What a generation will cost the user, before it has run.
 *
 * Text and reasoning token counts are only known afterwards, so the price is
 * a range. The actual price always falls between min and max.
 */
final readonly class CostEstimate
{
    public function __construct(
        public Money $min,
        public Money $max,
    ) {}
}
