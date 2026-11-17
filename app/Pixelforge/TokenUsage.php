<?php

namespace App\Pixelforge;

/**
 * How many tokens of each kind a generation used (or might use).
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}  $usage
     */
    public static function fromApi(array $usage): self
    {
        return new self(
            inputText: $usage['input_text_tokens'],
            inputImage: $usage['input_image_tokens'],
            output: $usage['output_tokens'],
            reasoning: $usage['reasoning_tokens'],
        );
    }
}
