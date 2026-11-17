<?php

namespace App\Http\Controllers;

use App\Actions\Generations\ApplyGenerationSnapshot;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge's webhooks (the signature is already checked).
 *
 * Events can arrive twice, out of order, or before our own submit request has
 * returned. Recording the event and applying it happen in one transaction:
 *
 *  - the unique event id makes a second delivery a no-op (answered with 200);
 *  - if applying fails, the event isn't recorded either, so we answer with an
 *    error and Pixelforge's automatic retry gets a clean second chance.
 *
 * The work is one small database update, so it runs inline, well inside
 * Pixelforge's 10 second limit, instead of in a queued job.
 */
class PixelforgeWebhookController extends Controller
{
    private const array GENERATION_EVENTS = ['generation.processing', 'generation.succeeded', 'generation.failed'];

    public function __invoke(Request $request, ApplyGenerationSnapshot $applySnapshot): Response
    {
        $event = $request->json()->all();

        if (! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response('Not a Pixelforge event.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $snapshot = null;

        if (in_array($event['type'], self::GENERATION_EVENTS, true)) {
            if (! is_array($event['data']['object'] ?? null)) {
                return response('Generation event without a generation.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $snapshot = GenerationData::fromApi($event['data']['object']);
        }

        $handled = DB::transaction(function () use ($event, $snapshot, $applySnapshot): bool {
            if (! $this->record($event, $snapshot)) {
                return false;
            }

            if ($snapshot !== null) {
                $this->apply($snapshot, $applySnapshot);
            }

            WebhookEvent::query()->where('event_id', $event['id'])->update(['processed_at' => now()]);

            return true;
        });

        if (! $handled) {
            // Already handled this event: acknowledge it so Pixelforge stops sending it.
            return response('Duplicate event, already handled.', Response::HTTP_OK);
        }

        return response()->noContent();
    }

    /**
     * Store the event, unless we already have it. Returns false for a duplicate.
     *
     * "Insert or ignore" leans on the unique event_id, so two copies arriving
     * at the same moment can't both get through.
     *
     * @param  array<string, mixed>  $event
     */
    private function record(array $event, ?GenerationData $snapshot): bool
    {
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'pixelforge_generation_id' => $snapshot?->id,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $inserted === 1;
    }

    private function apply(GenerationData $snapshot, ApplyGenerationSnapshot $applySnapshot): void
    {
        $generation = $this->findGeneration($snapshot);

        if ($generation === null) {
            Log::warning('Pixelforge webhook for a generation we do not know.', ['pixelforge_id' => $snapshot->id]);

            return;
        }

        $applySnapshot->handle($generation, $snapshot);
    }

    private function findGeneration(GenerationData $snapshot): ?Generation
    {
        $generation = Generation::query()->where('pixelforge_id', $snapshot->id)->first();

        if ($generation !== null) {
            return $generation;
        }

        // The webhook beat our submit request back, so we don't know the
        // Pixelforge id yet: find the row by the id we sent in the metadata.
        $ourId = $snapshot->metadata['generation_id'] ?? null;

        // Not one of our ids (Postgres would refuse to compare it with a bigint).
        if ($ourId === null || ! ctype_digit($ourId)) {
            return null;
        }

        return Generation::query()
            ->whereKey($ourId)
            ->whereNull('pixelforge_id')
            ->first();
    }
}
