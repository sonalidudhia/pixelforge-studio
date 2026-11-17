<?php

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationData;

/**
 * @param  array<string, mixed>  $payload
 */
function applySnapshot(Generation $generation, array $payload): Generation
{
    return app(ApplyGenerationSnapshot::class)->handle($generation, GenerationData::fromApi($payload));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function snapshotFor(Generation $generation, string $status, array $overrides = []): array
{
    $payload = $status === 'succeeded'
        ? succeededGenerationPayload()
        : generationPayload(['status' => $status]);

    return array_replace($payload, ['id' => $generation->pixelforge_id ?? 'gen_new'], $overrides);
}

it('records that Pixelforge accepted the generation', function () {
    $generation = Generation::factory()->create();

    $generation = applySnapshot($generation, snapshotFor($generation, 'queued'));

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->pixelforge_id)->toBe('gen_new')
        ->and($generation->billedRates()->reasoning->toDecimal())->toBe('12.500000000')
        ->and($generation->provider_cost)->toBeNull();
});

it('records the exact cost when the generation succeeds', function () {
    $generation = Generation::factory()->processing()->create();

    $generation = applySnapshot($generation, snapshotFor($generation, 'succeeded'));

    // 12 text, 765 image, 6,450 output, 3,184 reasoning tokens at the published rates.
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost->toDecimal())->toBe('0.305510000')
        ->and($generation->user_cost->toDecimal())->toBe('0.381887500')
        ->and($generation->tokenUsage()->reasoning)->toBe(3184)
        ->and($generation->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
});

it('prices with the rates on the generation, not today\'s rates', function () {
    $generation = Generation::factory()->processing()->create();

    $generation = applySnapshot($generation, snapshotFor($generation, 'succeeded', [
        'rates' => [
            'input_text_tokens' => '0.00',
            'input_image_tokens' => '0.00',
            'output_tokens' => '100.00',
            'reasoning_tokens' => '0.00',
        ],
    ]));

    // 6,450 output tokens at $100 per 1M.
    expect($generation->provider_cost->toDecimal())->toBe('0.645000000');
});

it('makes a failed generation free', function () {
    $generation = Generation::factory()->processing()->create();

    $generation = applySnapshot($generation, snapshotFor($generation, 'failed', [
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
    ]));

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->provider_cost->isZero())->toBeTrue()
        ->and($generation->user_cost->isZero())->toBeTrue()
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->error_message)->toBe('The model could not produce an image.');
});

it('ignores a late event that would move the status backwards', function () {
    $generation = Generation::factory()->processing()->create();
    applySnapshot($generation, snapshotFor($generation, 'succeeded'));

    $generation = applySnapshot($generation, snapshotFor($generation, 'processing'));

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost->toDecimal())->toBe('0.305510000');
});

it('records the cost only once when the same success arrives twice', function () {
    $generation = Generation::factory()->processing()->create();
    applySnapshot($generation, snapshotFor($generation, 'succeeded'));
    $firstSave = $generation->fresh()->updated_at;

    $this->travel(1)->minute();
    $generation = applySnapshot($generation, snapshotFor($generation, 'succeeded', [
        'usage' => ['input_text_tokens' => 999, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 0, 'total_tokens' => 1000],
    ]));

    expect($generation->provider_cost->toDecimal())->toBe('0.305510000')
        ->and($generation->updated_at)->toEqual($firstSave);
});

it('never turns a final status into the other one', function () {
    $generation = Generation::factory()->failed()->create();

    $generation = applySnapshot($generation, snapshotFor($generation, 'succeeded'));

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->user_cost->isZero())->toBeTrue();
});

it('skips straight to success when the processing event never came', function () {
    $generation = Generation::factory()->queued()->create();

    expect(applySnapshot($generation, snapshotFor($generation, 'succeeded'))->status)
        ->toBe(GenerationStatus::Succeeded);
});

it('lets Pixelforge correct a generation we gave up on too early', function () {
    // We gave up submitting, but one of the attempts reached Pixelforge after all.
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Failed,
        'error_code' => 'connection_failed',
    ]);

    $generation = applySnapshot($generation, snapshotFor($generation, 'succeeded'));

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->error_code)->toBeNull()
        ->and($generation->provider_cost->toDecimal())->toBe('0.305510000');
});

it('ignores a snapshot of a different Pixelforge generation', function () {
    $generation = Generation::factory()->processing()->create();

    $generation = applySnapshot($generation, snapshotFor($generation, 'succeeded', ['id' => 'gen_someone_else']));

    expect($generation->status)->toBe(GenerationStatus::Processing);
});

it('refuses to guess a price when a success has no usage', function () {
    $generation = Generation::factory()->processing()->create();

    expect(fn () => applySnapshot($generation, snapshotFor($generation, 'succeeded', ['usage' => null])))
        ->toThrow(UnexpectedValueException::class)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});
