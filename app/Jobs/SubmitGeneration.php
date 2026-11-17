<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\ErrorMessages;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\PixelforgeRejectedException;
use App\Pixelforge\Exceptions\PixelforgeRetryableException;
use App\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * Runs in the queue so the user never waits on Pixelforge, and so limits and
 * overloads can be waited out. Every attempt sends the same idempotency key,
 * so however often this runs, Pixelforge creates (and bills) one generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Enough patience for the concurrency limit to clear behind slow generations. */
    public const int MAX_ATTEMPTS = 8;

    private const int MAX_BACKOFF_SECONDS = 60;

    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $applySnapshot): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (a webhook can beat the POST response) or already given up on.
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        $generation->increment('submit_attempts');

        try {
            $snapshot = $client->createGeneration(
                prompt: $generation->prompt,
                size: $generation->size,
                quality: $generation->quality,
                referenceImages: $generation->reference_images,
                metadata: ['generation_id' => (string) $generation->id],
                idempotencyKey: $generation->idempotency_key,
            );
        } catch (PixelforgeRetryableException $exception) {
            if ($this->attempts() >= self::MAX_ATTEMPTS) {
                $this->giveUp($generation, $exception);

                return;
            }

            $this->release($this->secondsToWait($exception));

            return;
        } catch (PixelforgeRejectedException $exception) {
            $this->giveUp($generation, $exception);

            return;
        }

        $applySnapshot->handle($generation, $snapshot);
    }

    /**
     * Something unexpected went wrong on our side, more than the queue retries
     * could fix. Only a generation Pixelforge never accepted is marked failed.
     */
    public function failed(Throwable $exception): void
    {
        $generation = $this->generation->fresh();

        if ($generation?->status === GenerationStatus::Pending) {
            $this->markFailed($generation, 'submit_failed', 'Something went wrong while sending this image to Pixelforge. You were not charged.');
        }
    }

    /**
     * Wait at least as long as Pixelforge asked, and longer on every attempt.
     */
    private function secondsToWait(PixelforgeRetryableException $exception): int
    {
        $backoff = min(self::MAX_BACKOFF_SECONDS, 5 * 2 ** ($this->attempts() - 1));

        return max($backoff, $exception->retryAfter ?? 0);
    }

    private function giveUp(Generation $generation, PixelforgeException $exception): void
    {
        if ($exception->errorCode === 'invalid_api_key') {
            Log::critical('Pixelforge rejected our API key.', ['generation_id' => $generation->id]);
        }

        $this->markFailed(
            $generation,
            $exception->errorCode,
            ErrorMessages::forUser($exception->errorCode, $exception->getMessage()),
        );
    }

    private function markFailed(Generation $generation, string $errorCode, string $message): void
    {
        // Nothing was created at Pixelforge, so nothing is billed. If it was
        // created after all, its webhook still corrects this record.
        $generation->update([
            'status' => GenerationStatus::Failed,
            'provider_cost' => Money::zero(),
            'user_cost' => Money::zero(),
            'error_code' => $errorCode,
            'error_message' => $message,
        ]);
    }
}
