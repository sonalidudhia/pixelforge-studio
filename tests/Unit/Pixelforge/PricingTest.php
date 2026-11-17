<?php

use App\Pixelforge\Pricing;

beforeEach(function () {
    $this->pricing = Pricing::fromApi(pricingFixture());
});

it('reads the rates as exact money', function () {
    expect($this->pricing->rates->toApi())->toBe([
        'input_text_tokens' => '5.000000000',
        'input_image_tokens' => '10.000000000',
        'output_tokens' => '40.000000000',
        'reasoning_tokens' => '12.500000000',
    ]);
});

it('reads the token counting rules', function () {
    expect($this->pricing->charactersPerTextToken)->toBe(4)
        ->and($this->pricing->textTokenVarianceBasisPoints)->toBe(1_500)
        ->and($this->pricing->tokensPerReferenceImage)->toBe(765)
        ->and($this->pricing->outputTokensFor('high', '1536x1024'))->toBe(6_450)
        ->and($this->pricing->reasoningRangeFor('medium'))->toBe(['min' => 200, 'max' => 2_000]);
});

it('lists the qualities and sizes Pixelforge sells', function () {
    expect($this->pricing->qualities())->toBe(['low', 'medium', 'high'])
        ->and($this->pricing->sizes())->toBe(['1024x1024', '1536x1024', '1024x1536']);
});
