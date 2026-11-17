<?php

use App\Console\Commands\ReconcileGenerations;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

/**
 * A generation whose last news is older than the quiet window.
 *
 * @param  array<string, mixed>  $attributes
 */
function quietGeneration(string $state = 'processing', array $attributes = []): Generation
{
    $generation = Generation::factory()->{$state}()->create($attributes);
    $generation->forceFill(['updated_at' => now()->subSeconds(ReconcileGenerations::QUIET_FOR_SECONDS + 1)])->saveQuietly();

    return $generation;
}

it('asks Pixelforge about a generation whose webhook never came', function () {
    $generation = quietGeneration();
    Http::fake(["*/v1/generations/{$generation->pixelforge_id}" => Http::response(
        succeededGenerationPayload(['id' => $generation->pixelforge_id]),
    )]);

    $this->artisan('generations:reconcile')->assertSuccessful()->expectsOutputToContain('Polled 1');

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->user_cost->toDecimal()->toBe('0.381887500');
});

it('records a failure it finds by polling as free', function () {
    $generation = quietGeneration('queued');
    Http::fake(['*/v1/generations/*' => Http::response(generationPayload([
        'id' => $generation->pixelforge_id,
        'status' => 'failed',
        'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'],
        'completed_at' => '2026-11-04T14:03:29Z',
    ]))]);

    $this->artisan('generations:reconcile');

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->user_cost->isZero()->toBeTrue();
});

it('leaves recently updated generations to their webhooks', function () {
    Http::fake();
    Generation::factory()->processing()->create();

    $this->artisan('generations:reconcile')->expectsOutputToContain('Polled 0');

    Http::assertNothingSent();
});

it('never polls finished generations or ones Pixelforge has not accepted', function () {
    Http::fake();
    quietGeneration('succeeded');
    quietGeneration('failed');
    Generation::factory()->create(['updated_at' => now()->subSeconds(ReconcileGenerations::QUIET_FOR_SECONDS + 1)]);

    $this->artisan('generations:reconcile');

    Http::assertNothingSent();
});

it('polls a limited number per run, oldest first', function () {
    $oldest = quietGeneration();
    $oldest->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
    foreach (range(1, ReconcileGenerations::MAX_POLLS_PER_RUN + 2) as $i) {
        quietGeneration();
    }

    Http::fake(['*/v1/generations/*' => fn ($request) => Http::response(
        generationPayload(['id' => basename($request->url()), 'status' => 'processing']),
    )]);

    $this->artisan('generations:reconcile');

    Http::assertSentCount(ReconcileGenerations::MAX_POLLS_PER_RUN);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), $oldest->pixelforge_id));
});

it('stops for this run when Pixelforge says slow down', function () {
    quietGeneration();
    quietGeneration();
    Http::fake(['*/v1/generations/*' => Http::response(
        ['error' => ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'Slow down.']],
        429,
        ['Retry-After' => '30'],
    )]);

    $this->artisan('generations:reconcile')->assertSuccessful()->expectsOutputToContain('rate_limit_exceeded');

    Http::assertSentCount(1);
});

it('carries on past a generation Pixelforge cannot find', function () {
    $missing = quietGeneration();
    $missing->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
    $found = quietGeneration();

    Http::fake([
        "*/v1/generations/{$missing->pixelforge_id}" => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'not_found', 'message' => 'No such generation.']], 404),
        "*/v1/generations/{$found->pixelforge_id}" => Http::response(succeededGenerationPayload(['id' => $found->pixelforge_id])),
    ]);

    $this->artisan('generations:reconcile');

    expect($missing->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and($found->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('resubmits a generation whose submit job was lost', function () {
    $stuck = Generation::factory()->create();
    $stuck->forceFill(['updated_at' => now()->subMinutes(ReconcileGenerations::PENDING_FOR_MINUTES + 1)])->saveQuietly();
    $fresh = Generation::factory()->create();

    $this->artisan('generations:reconcile')->expectsOutputToContain('resubmitted 1');

    Queue::assertPushed(SubmitGeneration::class, 1);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($stuck));

    // Not resubmitted again on the next run while that job is still going.
    $this->artisan('generations:reconcile')->expectsOutputToContain('resubmitted 0');
});

it('runs every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('generations:reconcile');
});
