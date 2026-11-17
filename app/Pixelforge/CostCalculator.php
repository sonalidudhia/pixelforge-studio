<?php

namespace App\Pixelforge;

use App\Support\Money;

/**
 * All money rules live here:
 *
 *  - provider cost: what Pixelforge charges us (tokens x rate)
 *  - user price:    provider cost plus our markup
 *  - estimate:      the lowest and highest user price before a generation runs
 */
final readonly class CostCalculator
{
    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(private int $markupPercent) {}

    /**
     * What Pixelforge bills for this usage at these rates.
     */
    public function providerCost(TokenUsage $usage, Rates $rates): Money
    {
        // Rates are per 1M tokens, so add everything up first and divide once.
        $nanosTimesOneMillion =
            $usage->inputText * $rates->inputText->nanos
            + $usage->inputImage * $rates->inputImage->nanos
            + $usage->output * $rates->output->nanos
            + $usage->reasoning * $rates->reasoning->nanos;

        return Money::fromNanos(self::divideRoundingUp($nanosTimesOneMillion, self::TOKENS_PER_RATE_UNIT));
    }

    /**
     * What we charge the user: provider cost plus markup, rounded up to the
     * next nanodollar so we never charge less than cost plus markup.
     */
    public function userPrice(Money $providerCost): Money
    {
        return Money::fromNanos(
            self::divideRoundingUp($providerCost->nanos * (100 + $this->markupPercent), 100)
        );
    }

    /**
     * The price range to show the user before they submit.
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImages, Pricing $pricing): CostEstimate
    {
        $estimatedTextTokens = self::divideRoundingUp(mb_strlen($prompt), $pricing->charactersPerTextToken);
        $variance = $pricing->textTokenVarianceBasisPoints;

        $imageTokens = $referenceImages * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokensFor($quality, $size);
        $reasoning = $pricing->reasoningRangeFor($quality);

        $fewest = new TokenUsage(
            inputText: intdiv($estimatedTextTokens * (Pricing::BASIS_POINTS - $variance), Pricing::BASIS_POINTS),
            inputImage: $imageTokens,
            output: $outputTokens,
            reasoning: $reasoning['min'],
        );

        $most = new TokenUsage(
            inputText: self::divideRoundingUp($estimatedTextTokens * (Pricing::BASIS_POINTS + $variance), Pricing::BASIS_POINTS),
            inputImage: $imageTokens,
            output: $outputTokens,
            reasoning: $reasoning['max'],
        );

        return new CostEstimate(
            min: $this->userPrice($this->providerCost($fewest, $pricing->rates)),
            max: $this->userPrice($this->providerCost($most, $pricing->rates)),
        );
    }

    private static function divideRoundingUp(int $amount, int $divisor): int
    {
        return intdiv($amount + $divisor - 1, $divisor);
    }
}
