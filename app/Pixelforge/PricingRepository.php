<?php

namespace App\Pixelforge;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Reads Pixelforge's published pricing, so rates are never hardcoded.
 *
 * Pricing is cached briefly to keep the generate page fast and to save our
 * 60 requests per minute. If Pixelforge can't be reached we fall back to the
 * last pricing we saw; that is safe because the actual cost of a generation
 * is always calculated from the rates on the generation itself.
 */
final readonly class PricingRepository
{
    private const string FRESH_KEY = 'pixelforge.pricing';

    private const string LAST_KNOWN_KEY = 'pixelforge.pricing.last_known';

    private const int FRESH_FOR_SECONDS = 300;

    public function __construct(
        private Http $http,
        private Cache $cache,
        private string $baseUrl,
    ) {}

    public function current(): Pricing
    {
        return Pricing::fromApi(
            $this->cache->remember(self::FRESH_KEY, self::FRESH_FOR_SECONDS, fn (): array => $this->fetch())
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        try {
            $pricing = $this->http
                ->baseUrl($this->baseUrl)
                ->acceptJson()
                ->timeout(5)
                ->retry(2, 250)
                ->get('pricing')
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            return $this->cache->get(self::LAST_KNOWN_KEY) ?? throw $exception;
        }

        $this->cache->forever(self::LAST_KNOWN_KEY, $pricing);

        return $pricing;
    }
}
