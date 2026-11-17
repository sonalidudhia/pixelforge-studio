<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\Exceptions\PixelforgeRejectedException;
use App\Pixelforge\Exceptions\PixelforgeRetryableException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The safety net for lost news. Runs every minute.
 *
 * Webhook delivery isn't guaranteed, so generations that have been quiet for
 * a while are asked about directly (GET /generations/{id}). Generations that
 * never left our side, because a queue worker died, get their submit job
 * again; the idempotency key makes that safe.
 */
#[Signature('generations:reconcile')]
#[Description('Poll Pixelforge for generations whose webhooks have not arrived')]
class ReconcileGenerations extends Command
{
    /** A webhook normally arrives within seconds; after this, ask. */
    public const int QUIET_FOR_SECONDS = 60;

    /** Longer than the submit job's whole retry schedule (about 4 minutes). */
    public const int PENDING_FOR_MINUTES = 10;

    /** Each poll uses one of the 60 requests per minute that all users share. */
    public const int MAX_POLLS_PER_RUN = 10;

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $applySnapshot): int
    {
        $polled = $this->pollQuietGenerations($client, $applySnapshot);
        $resubmitted = $this->resubmitStuckGenerations();

        $this->info("Polled {$polled}, resubmitted {$resubmitted}.");

        return self::SUCCESS;
    }

    private function pollQuietGenerations(PixelforgeClient $client, ApplyGenerationSnapshot $applySnapshot): int
    {
        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<=', now()->subSeconds(self::QUIET_FOR_SECONDS))
            ->oldest('updated_at')
            ->limit(self::MAX_POLLS_PER_RUN)
            ->get();

        $polled = 0;

        foreach ($generations as $generation) {
            try {
                $applySnapshot->handle($generation, $client->getGeneration((string) $generation->pixelforge_id));
                $polled++;
            } catch (PixelforgeRetryableException $exception) {
                // Pixelforge is busy or we hit the rate limit: try the rest next minute.
                $this->warn("Stopped polling: {$exception->errorCode}.");

                break;
            } catch (PixelforgeRejectedException $exception) {
                Log::warning('Could not poll a Pixelforge generation.', [
                    'generation_id' => $generation->id,
                    'error_code' => $exception->errorCode,
                ]);
            }
        }

        return $polled;
    }

    private function resubmitStuckGenerations(): int
    {
        $generations = Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<=', now()->subMinutes(self::PENDING_FOR_MINUTES))
            ->get();

        foreach ($generations as $generation) {
            // Touch it so it isn't resubmitted again next minute while the job runs.
            $generation->touch();
            SubmitGeneration::dispatch($generation);
        }

        return $generations->count();
    }
}
