<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * The response of GET /v1/pricing: the current rates plus the rules for
 * counting tokens. Nothing here is hardcoded; it all comes from Pixelforge.
 */
final readonly class Pricing
{
    /** The text token variance is kept in basis points (0.15 = 1,500) so no float maths is needed. */
    public const int BASIS_POINTS = 10_000;

    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $pricing
     */
    public static function fromApi(array $pricing): self
    {
        $tokens = $pricing['tokens'];

        return new self(
            rates: Rates::fromApi($pricing['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            textTokenVarianceBasisPoints: (int) round($tokens['text_token_variance'] * self::BASIS_POINTS),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /**
     * @return list<string>
     */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /**
     * @return list<string>
     */
    public function sizes(): array
    {
        return array_keys(array_first($this->outputTokens) ?? []);
    }

    public function outputTokensFor(string $quality, string $size): int
    {
        return $this->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("Pixelforge has no price for quality [{$quality}] at size [{$size}].");
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRangeFor(string $quality): array
    {
        return $this->reasoningTokens[$quality]
            ?? throw new InvalidArgumentException("Pixelforge has no reasoning range for quality [{$quality}].");
    }
}
