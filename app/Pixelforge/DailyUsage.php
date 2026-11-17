<?php

namespace App\Pixelforge;

use App\Support\Money;

/**
 * One UTC day from GET /v1/usage: what Pixelforge billed this API key.
 */
final readonly class DailyUsage
{
    public function __construct(
        public string $date,
        public int $generations,
        public TokenUsage $tokens,
        public Money $cost,
    ) {}

    /**
     * @param  array{date: string, generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_usd: string}  $day
     */
    public static function fromApi(array $day): self
    {
        return new self(
            date: $day['date'],
            generations: $day['generations'],
            tokens: TokenUsage::fromApi($day),
            cost: Money::fromDecimal($day['cost_usd']),
        );
    }
}
