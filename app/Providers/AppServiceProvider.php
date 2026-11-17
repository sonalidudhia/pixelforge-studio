<?php

namespace App\Providers;

use App\Pixelforge\CostCalculator;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PricingRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
