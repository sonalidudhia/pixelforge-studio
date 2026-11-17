<?php

use App\Pixelforge\PricingRepository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('fetches pricing from Pixelforge without an API key', function () {
    Http::fake(['*/v1/pricing' => Http::response(pricingFixture())]);

    $pricing = app(PricingRepository::class)->current();

    expect($pricing->rates->output->toDecimal())->toBe('40.000000000');

    Http::assertSent(fn ($request) => $request->url() === 'https://pixelforge.promptedit.dev/v1/pricing'
        && ! $request->hasHeader('Authorization'));
});

it('caches pricing instead of asking every time', function () {
    Http::fake(['*/v1/pricing' => Http::response(pricingFixture())]);

    app(PricingRepository::class)->current();
    app(PricingRepository::class)->current();

    Http::assertSentCount(1);
});

it('picks up a price change once the cache expires', function () {
    $changed = pricingFixture();
    $changed['rates']['output_tokens'] = '44.00';

    Http::fake(['*/v1/pricing' => Http::sequence()
        ->push(pricingFixture())
        ->push($changed)]);

    app(PricingRepository::class)->current();

    $this->travel(6)->minutes();

    expect(app(PricingRepository::class)->current()->rates->output->toDecimal())->toBe('44.000000000');
});

it('falls back to the last known pricing when Pixelforge is down', function () {
    Http::fake(['*/v1/pricing' => Http::sequence()
        ->push(pricingFixture())
        ->whenEmpty(Http::response(['error' => ['code' => 'overloaded']], 503))]);

    app(PricingRepository::class)->current();

    $this->travel(6)->minutes();

    expect(app(PricingRepository::class)->current()->rates->output->toDecimal())->toBe('40.000000000');
});

it('fails loudly when Pixelforge is down and no pricing was ever seen', function () {
    Cache::flush();
    Http::fake(['*/v1/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    app(PricingRepository::class)->current();
})->throws(RequestException::class);
