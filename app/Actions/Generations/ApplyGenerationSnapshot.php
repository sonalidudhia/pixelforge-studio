<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\CostCalculator;
use App\Pixelforge\GenerationData;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * Brings our generation up to date with what Pixelforge says about it.
 *
 * Every source of news goes through here: the POST response, webhooks and
 * polling. They can arrive at the same time, twice, or in any order, so:
 *
 *  - the row is locked while it is updated, so two updates never interleave;
 *  - the status only moves forward, so a late event can't undo a final one;
 *  - the cost is set exactly once, in the same step that makes it final.
 */
final readonly class ApplyGenerationSnapshot
{
    public function __construct(private CostCalculator $calculator) {}

    public function handle(Generation $generation, GenerationData $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot): Generation {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->pixelforge_id !== null && $generation->pixelforge_id !== $snapshot->id) {
                Log::warning('Pixelforge snapshot does not belong to this generation.', [
                    'generation_id' => $generation->id,
                    'expected' => $generation->pixelforge_id,
                    'received' => $snapshot->id,
                ]);

                return $generation;
            }

            if (! $this->shouldApply($generation, $snapshot)) {
                return $generation;
            }

            $generation->fill([
                'pixelforge_id' => $snapshot->id,
                'status' => $snapshot->status,
                'rates' => $snapshot->rates->toApi(),
                'started_at' => $snapshot->startedAt,
                'completed_at' => $snapshot->completedAt,
                'error_code' => null,
                'error_message' => null,
            ]);

            match ($snapshot->status) {
                GenerationStatus::Succeeded => $this->recordSuccess($generation, $snapshot),
                GenerationStatus::Failed => $this->recordFailure($generation, $snapshot),
                default => null,
            };

            $generation->save();

            return $generation;
        });
    }

    private function shouldApply(Generation $generation, GenerationData $snapshot): bool
    {
        // Until Pixelforge has accepted it, our status is only our own guess.
        // If we gave up submitting but the request did reach Pixelforge after
        // all, Pixelforge's word wins, so our records match what it bills.
        if ($generation->pixelforge_id === null) {
            return true;
        }

        return $generation->status->canTransitionTo($snapshot->status);
    }

    private function recordSuccess(Generation $generation, GenerationData $snapshot): void
    {
        $usage = $snapshot->usage;

        if ($usage === null) {
            // Pixelforge always sends usage with a success; refuse to guess a price.
            throw new UnexpectedValueException("Pixelforge generation [{$snapshot->id}] succeeded without usage.");
        }

        // The generation's own rates, fixed when it was created, not today's.
        $providerCost = $this->calculator->providerCost($usage, $snapshot->rates);

        $generation->fill([
            'input_text_tokens' => $usage->inputText,
            'input_image_tokens' => $usage->inputImage,
            'output_tokens' => $usage->output,
            'reasoning_tokens' => $usage->reasoning,
            'provider_cost' => $providerCost,
            'user_cost' => $this->calculator->userPrice($providerCost),
            'image_url' => $snapshot->imageUrl,
            'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
        ]);
    }

    private function recordFailure(Generation $generation, GenerationData $snapshot): void
    {
        // Pixelforge never bills a failed generation, so neither do we.
        $generation->fill([
            'provider_cost' => Money::zero(),
            'user_cost' => Money::zero(),
            'error_code' => $snapshot->errorCode,
            'error_message' => $snapshot->errorMessage,
        ]);
    }
}
