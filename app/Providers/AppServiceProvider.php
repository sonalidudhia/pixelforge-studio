<?php

namespace App\Providers;

use App\Pixelforge\CostCalculator;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PricingRepository;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CostCalculator::class, fn (): CostCalculator => new CostCalculator(
            markupPercent: (int) config('services.pixelforge.markup_percent'),
        ));

        $this->app->singleton(PricingRepository::class, fn (Application $app): PricingRepository => new PricingRepository(
            http: $app->make(HttpFactory::class),
            cache: $app->make(CacheRepository::class),
            baseUrl: (string) config('services.pixelforge.base_url'),
        ));

        $this->app->singleton(PixelforgeClient::class, fn (Application $app): PixelforgeClient => new PixelforgeClient(
            http: $app->make(HttpFactory::class),
            baseUrl: (string) config('services.pixelforge.base_url'),
            apiKey: (string) config('services.pixelforge.key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimits();
    }

    /**
     * Named limiters, each with its own counter. (A plain "throttle:10,1"
     * keys by user only, so every throttled route would share one counter
     * and live estimates would use up the submit limit.)
     */
    protected function configureRateLimits(): void
    {
        // Each submit can cost money and uses Pixelforge limits that all users share.
        RateLimiter::for('generation-submits', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('generation-submits:'.$request->user()?->getAuthIdentifier()));

        // Estimates are cheap (cached pricing, no Pixelforge call) and fire while typing.
        RateLimiter::for('generation-estimates', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('generation-estimates:'.$request->user()?->getAuthIdentifier()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
