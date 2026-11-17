<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Testing\TestResponse;

const TEST_WEBHOOK_SECRET = 'whsec_test_secret';

/**
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function webhookEvent(string $type, array $generation, string $eventId = 'evt_1'): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

function signPixelforgePayload(string $body, int $timestamp, string $secret = TEST_WEBHOOK_SECRET): string
{
    return 't='.$timestamp.',v1='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

/**
 * Send an event the way Pixelforge does: raw JSON body, signed.
 *
 * @param  array<string, mixed>  $event
 */
function sendWebhook(array $event, ?string $signature = null, ?string $body = null): TestResponse
{
    $body ??= json_encode($event, JSON_THROW_ON_ERROR);
    $signature ??= signPixelforgePayload($body, time());

    return test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'] ?? '',
    ], content: $body);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeObjectFor(Generation $generation, string $status, array $overrides = []): array
{
    $payload = match ($status) {
        'succeeded' => succeededGenerationPayload(),
        'failed' => generationPayload([
            'status' => 'failed',
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
            'completed_at' => '2026-11-04T14:03:29Z',
        ]),
        default => generationPayload(['status' => $status]),
    };

    return array_replace($payload, [
        'id' => $generation->pixelforge_id ?? 'gen_from_webhook',
        'metadata' => ['generation_id' => (string) $generation->id],
    ], $overrides);
}

describe('signature', function () {
    beforeEach(function () {
        $this->generation = Generation::factory()->processing()->create();
        $this->event = webhookEvent('generation.succeeded', pixelforgeObjectFor($this->generation, 'succeeded'));
    });

    it('accepts a correctly signed event', function () {
        sendWebhook($this->event)->assertNoContent();

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('refuses an event that is not signed correctly', function (string $problem) {
        $body = json_encode($this->event);

        $signature = match ($problem) {
            'no signature' => '',
            'garbage' => 'nonsense',
            'wrong secret' => signPixelforgePayload($body, time(), 'whsec_someone_else'),
            'signed a different body' => signPixelforgePayload($body.' ', time()),
            'too old: a replay' => signPixelforgePayload($body, time() - 301),
            'too far in the future' => signPixelforgePayload($body, time() + 301),
        };

        sendWebhook($this->event, signature: $signature)->assertStatus(400);

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing)
            ->and(WebhookEvent::count())->toBe(0);
    })->with(['no signature', 'garbage', 'wrong secret', 'signed a different body', 'too old: a replay', 'too far in the future']);

    it('accepts an event signed within the last five minutes', function () {
        $body = json_encode($this->event);

        sendWebhook($this->event, signature: signPixelforgePayload($body, time() - 299))->assertNoContent();
    });

    it('checks the raw body, not a re-encoded copy', function () {
        // Same JSON, different whitespace: the signature must cover these exact bytes.
        $body = json_encode($this->event, JSON_PRETTY_PRINT);

        sendWebhook($this->event, signature: signPixelforgePayload($body, time()), body: $body)->assertNoContent();
    });

    it('refuses everything when no signing secret is set', function () {
        config(['services.pixelforge.webhook_secret' => '']);
        $body = json_encode($this->event);

        sendWebhook($this->event, signature: signPixelforgePayload($body, time(), ''))->assertStatus(400);
    });

    it('needs no session or CSRF token', function () {
        // The test would get 419 if the route were in the web middleware group.
        sendWebhook($this->event)->assertNoContent()->assertCookieMissing('XSRF-TOKEN');
    });
});

describe('duplicates', function () {
    it('handles an event delivered twice only once', function () {
        $generation = Generation::factory()->processing()->create();
        $event = webhookEvent('generation.succeeded', pixelforgeObjectFor($generation, 'succeeded'));

        sendWebhook($event)->assertNoContent();
        $this->travel(1)->minute();
        sendWebhook($event)->assertOk()->assertSee('Duplicate');

        expect(WebhookEvent::count())->toBe(1)
            ->and($generation->fresh()->user_cost->toDecimal())->toBe('0.381887500');
    });

    it('treats different events about the same generation as different', function () {
        $generation = Generation::factory()->queued()->create();

        sendWebhook(webhookEvent('generation.processing', pixelforgeObjectFor($generation, 'processing'), 'evt_1'));
        sendWebhook(webhookEvent('generation.succeeded', pixelforgeObjectFor($generation, 'succeeded'), 'evt_2'));

        expect(WebhookEvent::count())->toBe(2)
            ->and($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });
});

describe('order', function () {
    it('keeps the final status when a late processing event arrives', function () {
        $generation = Generation::factory()->queued()->create();

        sendWebhook(webhookEvent('generation.succeeded', pixelforgeObjectFor($generation, 'succeeded'), 'evt_2'));
        sendWebhook(webhookEvent('generation.processing', pixelforgeObjectFor($generation, 'processing'), 'evt_1'))->assertNoContent();

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Succeeded)
            ->user_cost->toDecimal()->toBe('0.381887500');
    });

    it('keeps a failure free when a late processing event arrives', function () {
        $generation = Generation::factory()->queued()->create();

        sendWebhook(webhookEvent('generation.failed', pixelforgeObjectFor($generation, 'failed'), 'evt_2'));
        sendWebhook(webhookEvent('generation.processing', pixelforgeObjectFor($generation, 'processing'), 'evt_1'));

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Failed)
            ->user_cost->isZero()->toBeTrue();
    });

    it('handles a webhook that arrives before our submit request returned', function () {
        // Still pending: we don't know the Pixelforge id yet.
        $generation = Generation::factory()->create();

        sendWebhook(webhookEvent('generation.processing', pixelforgeObjectFor($generation, 'processing', ['id' => 'gen_early'])))
            ->assertNoContent();

        expect($generation->fresh())
            ->status->toBe(GenerationStatus::Processing)
            ->pixelforge_id->toBe('gen_early');
    });

    it('does not let metadata point a webhook at another accepted generation', function () {
        $generation = Generation::factory()->queued()->create();

        // Claims to be about our generation, but carries a different Pixelforge id.
        sendWebhook(webhookEvent('generation.succeeded', pixelforgeObjectFor($generation, 'succeeded', ['id' => 'gen_other'])))
            ->assertNoContent();

        expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
    });
});

describe('other events', function () {
    it('acknowledges a test ping', function () {
        sendWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z', 'data' => []])
            ->assertNoContent();

        expect(WebhookEvent::sole()->type)->toBe('ping');
    });

    it('acknowledges an event about a generation we do not know', function () {
        sendWebhook(webhookEvent('generation.succeeded', array_replace(succeededGenerationPayload(), ['id' => 'gen_unknown', 'metadata' => []])))
            ->assertNoContent();

        expect(WebhookEvent::sole()->processed_at)->not->toBeNull();
    });

    it('ignores metadata that is not one of our ids', function () {
        sendWebhook(webhookEvent('generation.succeeded', array_replace(succeededGenerationPayload(), [
            'id' => 'gen_unknown',
            'metadata' => ['generation_id' => 'not-a-number'],
        ])))->assertNoContent();
    });

    it('refuses a body that is not an event', function () {
        sendWebhook(['hello' => 'world'])->assertStatus(422);
        sendWebhook(['id' => 'evt_x', 'type' => 'generation.succeeded', 'data' => []])->assertStatus(422);

        expect(WebhookEvent::count())->toBe(0);
    });
});

describe('failures while handling', function () {
    it('forgets the event when handling fails, so Pixelforge\'s retry can succeed', function () {
        $generation = Generation::factory()->processing()->create();
        $event = webhookEvent('generation.succeeded', pixelforgeObjectFor($generation, 'succeeded'));

        // The first save of the generation blows up, like a lost database connection.
        $failNextSave = true;
        Generation::saving(function () use (&$failNextSave) {
            if ($failNextSave) {
                $failNextSave = false;
                throw new RuntimeException('Database hiccup');
            }
        });

        sendWebhook($event)->assertServerError();

        expect(WebhookEvent::count())->toBe(0)
            ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);

        // Pixelforge retries the same event later.
        sendWebhook($event)->assertNoContent();

        expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and(WebhookEvent::sole()->processed_at)->not->toBeNull();
    });
});
