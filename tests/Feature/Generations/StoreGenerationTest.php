<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    fakePixelforgePricing();

    $this->user = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validGenerationInput(array $overrides = []): array
{
    return array_replace([
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
    ], $overrides);
}

it('requires signing in', function () {
    $this->post(route('generations.store'), validGenerationInput())->assertRedirect(route('login'));

    expect(Generation::count())->toBe(0);
});

it('saves the request with its estimate and queues it', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('generations.store'), validGenerationInput())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $generation = $this->user->generations()->sole();

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->idempotency_key)->toBeUuid()
        ->and($generation->reference_images)->toBe(['https://example.com/style.png'])
        ->and($generation->estimated_min_cost->toDecimal())->toBe('0.347750000')
        ->and($generation->estimated_max_cost->toDecimal())->toBe('0.457150000');

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

it('accepts a request without reference images', function () {
    Queue::fake();

    $input = validGenerationInput();
    unset($input['reference_images']);

    $this->actingAs($this->user)->post(route('generations.store'), $input)->assertSessionHasNoErrors();

    expect($this->user->generations()->sole()->reference_images)->toBe([]);
});

it('sends the request to Pixelforge through the queue', function () {
    // The test queue runs jobs straight away.
    Http::fake(['*/v1/generations' => Http::response(generationPayload(['id' => 'gen_accepted']), 202)]);

    $this->actingAs($this->user)->post(route('generations.store'), validGenerationInput());

    expect($this->user->generations()->sole())
        ->status->toBe(GenerationStatus::Queued)
        ->pixelforge_id->toBe('gen_accepted');
});

it('checks the input before anything is queued', function (array $input, string $field) {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('generations.store'), validGenerationInput($input))
        ->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no prompt' => [['prompt' => ''], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'reference image is not a URL' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'reference image is not http' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
]);

it('counts the prompt limit in characters, not bytes', function () {
    Queue::fake();

    // 4,000 characters, 16,000 bytes.
    $this->actingAs($this->user)
        ->post(route('generations.store'), validGenerationInput(['prompt' => str_repeat('🎨', 4000)]))
        ->assertSessionHasNoErrors();
});
