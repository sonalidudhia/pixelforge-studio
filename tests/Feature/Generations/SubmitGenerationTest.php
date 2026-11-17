<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
});

function runSubmitJob(Generation $generation, int $attempt = 1): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;

    app()->call([$job, 'handle']);

    return $job;
}

/**
 * @return array<string, mixed>
 */
function pixelforgeError(string $code): array
{
    return ['error' => ['type' => 'api_error', 'code' => $code, 'message' => "Pixelforge says {$code}."]];
}

it('submits the generation and records that Pixelforge accepted it', function () {
    Http::fake(['*/v1/generations' => Http::response(generationPayload(['id' => 'gen_accepted']), 202)]);
    $generation = Generation::factory()->create();

    runSubmitJob($generation)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe('gen_accepted')
        ->and($generation->submit_attempts)->toBe(1);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
        && $request['metadata'] === ['generation_id' => (string) $generation->id]
        && $request['prompt'] === $generation->prompt
        && $request['reference_images'] === $generation->reference_images);
});

it('does nothing when the generation was already accepted', function () {
    Http::fake();
    // e.g. the webhook arrived before an earlier attempt's response did.
    $generation = Generation::factory()->processing()->create();

    runSubmitJob($generation);

    Http::assertNothingSent();
});

it('waits and tries again when Pixelforge is busy', function (string $code, int $status, int $retryAfter, int $attempt, int $expectedWait) {
    Http::fake(['*/v1/generations' => Http::response(pixelforgeError($code), $status, ['Retry-After' => (string) $retryAfter])]);
    $generation = Generation::factory()->create();

    runSubmitJob($generation, $attempt)->assertReleased(delay: $expectedWait);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'overloaded, first attempt: our backoff is longer' => ['overloaded', 503, 2, 1, 5],
    'concurrency, third attempt: backoff grows' => ['concurrency_limit_exceeded', 429, 5, 3, 20],
    'rate limit: Retry-After is longer' => ['rate_limit_exceeded', 429, 45, 1, 45],
    'backoff is capped at a minute' => ['overloaded', 503, 2, 7, 60],
]);

it('sends the same idempotency key on every attempt', function () {
    Http::fake(['*/v1/generations' => Http::sequence()
        ->push(pixelforgeError('overloaded'), 503, ['Retry-After' => '2'])
        ->push(generationPayload(['id' => 'gen_accepted']), 202)]);
    $generation = Generation::factory()->create();

    runSubmitJob($generation, 1);
    runSubmitJob($generation, 2);

    $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])->unique();

    expect($keys->all())->toBe([$generation->idempotency_key])
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()->submit_attempts)->toBe(2);
});

it('gives up after the last attempt and charges nothing', function () {
    Http::fake(['*/v1/generations' => Http::response(pixelforgeError('overloaded'), 503, ['Retry-After' => '2'])]);
    $generation = Generation::factory()->create();

    runSubmitJob($generation, SubmitGeneration::MAX_ATTEMPTS)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('overloaded')
        ->and($generation->error_message)->toContain('too busy')
        ->and($generation->user_cost->isZero())->toBeTrue()
        ->and($generation->provider_cost->isZero())->toBeTrue();
});

it('fails straight away when Pixelforge rejects the request', function (string $code, int $status, string $message) {
    Http::fake(['*/v1/generations' => Http::response(pixelforgeError($code), $status)]);
    $generation = Generation::factory()->create();

    runSubmitJob($generation)->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->error_message)->toContain($message)
        ->and($generation->user_cost->isZero())->toBeTrue();
})->with([
    ['content_policy_violation', 400, 'rejected this prompt'],
    ['daily_limit_exceeded', 429, 'daily image limit'],
    // No friendly text of our own: Pixelforge's message is passed on.
    ['validation_failed', 422, 'Pixelforge says validation_failed.'],
]);

it('raises the alarm when the API key is rejected', function () {
    Log::spy();
    Http::fake(['*/v1/generations' => Http::response(pixelforgeError('invalid_api_key'), 401)]);
    $generation = Generation::factory()->create();

    runSubmitJob($generation);

    Log::shouldHaveReceived('critical')->once();
    expect($generation->fresh()->error_message)->toContain('not set up correctly');
});

it('marks a never-accepted generation as failed when the job crashes', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Boom'));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->user_cost->isZero())->toBeTrue();
});

it('leaves an accepted generation alone when the job crashes', function () {
    $generation = Generation::factory()->queued()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Boom'));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});
