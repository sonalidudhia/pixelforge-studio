<?php

namespace App\Pixelforge;

use App\Support\Money;

/**
 * Pixelforge's price for each kind of token, per 1,000,000 tokens.
 *
 * Every generation carries the rates it is billed at, so the same class is
 * used for today's published rates and for a generation's own snapshot.
 */
final readonly class Rates
{
    public function __construct(
        public Money $inputText,
        public Money $inputImage,
        public Money $output,
        public Money $reasoning,
    ) {}

    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public static function fromApi(array $rates): self
    {
        return new self(
            inputText: Money::fromDecimal($rates['input_text_tokens']),
            inputImage: Money::fromDecimal($rates['input_image_tokens']),
            output: Money::fromDecimal($rates['output_tokens']),
            reasoning: Money::fromDecimal($rates['reasoning_tokens']),
        );
    }

    /**
     * The same shape Pixelforge sends, so a snapshot can be stored as JSON.
     *
     * @return array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}
     */
    public function toApi(): array
    {
        return [
            'input_text_tokens' => $this->inputText->toDecimal(),
            'input_image_tokens' => $this->inputImage->toDecimal(),
            'output_tokens' => $this->output->toDecimal(),
            'reasoning_tokens' => $this->reasoning->toDecimal(),
        ];
    }
}
