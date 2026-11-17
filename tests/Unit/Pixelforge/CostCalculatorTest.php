<?php

use App\Pixelforge\CostCalculator;
use App\Pixelforge\Pricing;
use App\Pixelforge\Rates;
use App\Pixelforge\TokenUsage;
use App\Support\Money;

beforeEach(function () {
    $this->calculator = new CostCalculator(markupPercent: 25);
    $this->pricing = Pricing::fromApi(pricingFixture());
});

describe('provider cost', function () {
    it('matches the worked example in the Pixelforge docs', function () {
        $usage = new TokenUsage(inputText: 115, inputImage: 1_530, output: 6_450, reasoning: 8_000);

        expect($this->calculator->providerCost($usage, $this->pricing->rates)->toDecimal())
            ->toBe('0.373875000');
    });

    it('matches the usage example in the Pixelforge docs', function () {
        $usage = new TokenUsage(inputText: 41, inputImage: 0, output: 3_300, reasoning: 2_950);

        expect($this->calculator->providerCost($usage, $this->pricing->rates)->toDecimal())
            ->toBe('0.169080000');
    });

    it('prices one token at $1.00 per 1M as exactly $0.000001', function () {
        $rates = Rates::fromApi([
            'input_text_tokens' => '1.00',
            'input_image_tokens' => '1.00',
            'output_tokens' => '1.00',
            'reasoning_tokens' => '1.00',
        ]);

        expect($this->calculator->providerCost(new TokenUsage(1, 0, 0, 0), $rates)->toDecimal())
            ->toBe('0.000001000');
    });

    it('uses the rates it is given, not the published ones', function () {
        $oldRates = Rates::fromApi([
            'input_text_tokens' => '1.00',
            'input_image_tokens' => '2.00',
            'output_tokens' => '3.00',
            'reasoning_tokens' => '4.00',
        ]);

        // 1 + 2 + 3 + 4 dollars per 1M, with 1M tokens of each kind.
        $usage = new TokenUsage(1_000_000, 1_000_000, 1_000_000, 1_000_000);

        expect($this->calculator->providerCost($usage, $oldRates)->toDecimal())->toBe('10.000000000');
    });

    it('costs nothing when no tokens were used', function () {
        expect($this->calculator->providerCost(new TokenUsage(0, 0, 0, 0), $this->pricing->rates)->isZero())
            ->toBeTrue();
    });
});

describe('user price', function () {
    it('adds a 25% markup', function () {
        expect($this->calculator->userPrice(Money::fromDecimal('0.373875000'))->toDecimal())
            ->toBe('0.467343750');
    });

    it('rounds a fraction of a nanodollar up, never down', function () {
        // 1 nanodollar + 25% = 1.25 nanodollars.
        expect($this->calculator->userPrice(Money::fromNanos(1))->nanos)->toBe(2);
    });

    it('keeps free generations free', function () {
        expect($this->calculator->userPrice(Money::zero())->isZero())->toBeTrue();
    });
});

describe('estimate', function () {
    it('gives the docs worked example as the maximum, plus markup', function () {
        $estimate = $this->calculator->estimate(
            promptCharacters: 400,
            size: '1536x1024',
            quality: 'high',
            referenceImages: 2,
            pricing: $this->pricing,
        );

        // Max: 115 text, 1,530 image, 6,450 output, 8,000 reasoning = 0.373875 + 25%.
        // Min:  85 text, 1,530 image, 6,450 output, 1,000 reasoning = 0.286225 + 25%.
        expect($estimate->max->toDecimal())->toBe('0.467343750')
            ->and($estimate->min->toDecimal())->toBe('0.357781250');
    });

    it('rounds a partial text token up', function () {
        // 5 characters = 2 tokens; at most 3 after +15%, at least 1 after -15%.
        $estimate = $this->calculator->estimate(5, '1024x1024', 'low', 0, $this->pricing);

        $output = 280 * 40_000;

        expect($estimate->min->nanos)->toBe((int) ((1 * 5_000 + $output) * 1.25))
            ->and($estimate->max->nanos)->toBe((int) ((3 * 5_000 + $output + 500 * 12_500) * 1.25));
    });

    it('charges 765 image tokens per reference image', function () {
        $without = $this->calculator->estimate(5, '1024x1024', 'medium', 0, $this->pricing);
        $withOne = $this->calculator->estimate(5, '1024x1024', 'medium', 1, $this->pricing);

        // 765 tokens x $10.00 per 1M = $0.00765, plus 25%.
        expect($withOne->max->minus($without->max)->toDecimal())->toBe('0.009562500')
            ->and($withOne->min->minus($without->min)->toDecimal())->toBe('0.009562500');
    });

    it('always contains the actual price', function (string $quality, string $size) {
        $promptCharacters = 185;
        $estimate = $this->calculator->estimate($promptCharacters, $size, $quality, 1, $this->pricing);

        $estimatedTextTokens = (int) ceil($promptCharacters / 4);
        $reasoning = $this->pricing->reasoningRangeFor($quality);

        // Every text token count Pixelforge may report: within 15% either way.
        $possibleTextTokens = range(
            (int) ceil($estimatedTextTokens * 0.85),
            (int) floor($estimatedTextTokens * 1.15),
        );

        foreach ($possibleTextTokens as $textTokens) {
            foreach ([$reasoning['min'], $reasoning['max']] as $reasoningTokens) {
                $actual = $this->calculator->userPrice($this->calculator->providerCost(
                    new TokenUsage($textTokens, 765, $this->pricing->outputTokensFor($quality, $size), $reasoningTokens),
                    $this->pricing->rates,
                ));

                expect($actual->nanos)->toBeBetween($estimate->min->nanos, $estimate->max->nanos);
            }
        }
    })->with(['low', 'medium', 'high'])->with(['1024x1024', '1536x1024', '1024x1536']);

    it('refuses a quality or size Pixelforge does not sell', function (string $size, string $quality) {
        $this->calculator->estimate(5, $size, $quality, 0, $this->pricing);
    })->with([
        ['512x512', 'low'],
        ['1024x1024', 'ultra'],
    ])->throws(InvalidArgumentException::class);
});
