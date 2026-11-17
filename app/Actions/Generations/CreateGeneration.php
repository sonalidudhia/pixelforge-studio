<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\CostCalculator;
use App\Pixelforge\PricingRepository;
use Illuminate\Support\Str;

/**
 * Saves a user's request and queues it for Pixelforge.
 *
 * The row is saved before Pixelforge hears about it, so a webhook that
 * arrives before the submit finishes always finds a generation to update.
 */
final readonly class CreateGeneration
{
    public function __construct(
        private PricingRepository $pricing,
        private CostCalculator $calculator,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     */
    public function handle(User $user, string $prompt, string $size, string $quality, array $referenceImages): Generation
    {
        $estimate = $this->calculator->estimate(mb_strlen($prompt), $size, $quality, count($referenceImages), $this->pricing->current());

        $generation = $user->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Pending,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'estimated_min_cost' => $estimate->min,
            'estimated_max_cost' => $estimate->max,
        ]);

        SubmitGeneration::dispatch($generation)->afterCommit();

        return $generation;
    }
}
