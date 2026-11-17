<?php

use App\Enums\GenerationStatus;
use App\Pixelforge\Exceptions\PixelforgeRejectedException;
use App\Pixelforge\Exceptions\PixelforgeRetryableException;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.pixelforge.key' => 'pf_test_key']);

    $this->client = app(PixelforgeClient::class);
});

/**
 * @return array<string, mixed>
 */
function errorPayload(string $code, string $type = 'api_error'): array
{
    return ['error' => ['type' => $type, 'code' => $code, 'message' => "Message for {$code}."]];
}

function createTestGeneration(PixelforgeClient $client): mixed
{
    return $client->createGeneration(
        prompt: 'A lighthouse on a cliff at dawn, oil painting',
        size: '1536x1024',
        quality: 'high',
        referenceImages: ['https://example.com/style.png'],
        metadata: ['generation_id' => '1'],
        idempotencyKey: '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10',
    );
}

describe('creating a generation', function () {
    it('posts the parameters with the API key and idempotency key', function () {
        Http::fake(['*/v1/generations' => Http::response(generationPayload(), 202)]);

        $generation = createTestGeneration($this->client);

        expect($generation->id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
            ->and($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->usage)->toBeNull()
            ->and($generation->metadata)->toBe(['generation_id' => '1'])
            ->and($generation->rates->reasoning->toDecimal())->toBe('12.500000000');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://pixelforge.promptedit.dev/v1/generations'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->hasHeader('Idempotency-Key', '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10')
            && $request->data() === [
                'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_images' => ['https://example.com/style.png'],
                'metadata' => ['generation_id' => '1'],
            ]);
    });

    it('leaves out empty optional parameters', function () {
        Http::fake(['*/v1/generations' => Http::response(generationPayload(), 202)]);

        $this->client->createGeneration('A cat', '1024x1024', 'low', [], [], 'key-1');

        // An empty PHP array would be sent as [] where Pixelforge expects an object.
        Http::assertSent(fn (Request $request) => array_keys($request->data()) === ['prompt', 'size', 'quality']);
    });

    it('makes exactly one attempt and leaves retrying to the caller', function () {
        Http::fake(['*/v1/generations' => Http::response(errorPayload('overloaded'), 503)]);

        try {
            createTestGeneration($this->client);
        } catch (PixelforgeRetryableException) {
            // expected
        }

        Http::assertSentCount(1);
    });
});

describe('reading a generation', function () {
    it('reads a succeeded generation with usage and image', function () {
        Http::fake(['*/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(succeededGenerationPayload())]);

        $generation = $this->client->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->usage->inputText)->toBe(12)
            ->and($generation->usage->inputImage)->toBe(765)
            ->and($generation->usage->output)->toBe(6450)
            ->and($generation->usage->reasoning)->toBe(3184)
            ->and($generation->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/')
            ->and($generation->imageUrlExpiresAt->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
            ->and($generation->completedAt->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');
    });

    it('reads a failed generation with its error', function () {
        Http::fake(['*/v1/generations/*' => Http::response(generationPayload([
            'status' => 'failed',
            'error' => ['code' => 'internal_error', 'message' => 'Something went wrong on our side.'],
            'completed_at' => '2026-11-04T14:03:29Z',
        ]))]);

        $generation = $this->client->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->errorCode)->toBe('internal_error')
            ->and($generation->errorMessage)->toBe('Something went wrong on our side.')
            ->and($generation->usage)->toBeNull();
    });
});

describe('errors', function () {
    it('treats temporary problems as retryable and passes on Retry-After', function (int $status, string $code, int $retryAfter) {
        Http::fake(['*' => Http::response(errorPayload($code), $status, ['Retry-After' => (string) $retryAfter])]);

        try {
            createTestGeneration($this->client);
            $this->fail('Expected a retryable exception.');
        } catch (PixelforgeRetryableException $exception) {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->httpStatus)->toBe($status)
                ->and($exception->retryAfter)->toBe($retryAfter)
                ->and($exception->getMessage())->toBe("Message for {$code}.");
        }
    })->with([
        [429, 'rate_limit_exceeded', 37],
        [429, 'concurrency_limit_exceeded', 5],
        [503, 'overloaded', 2],
    ]);

    it('treats permanent problems as rejected', function (int $status, string $code) {
        Http::fake(['*' => Http::response(errorPayload($code), $status)]);

        try {
            createTestGeneration($this->client);
            $this->fail('Expected a rejected exception.');
        } catch (PixelforgeRejectedException $exception) {
            expect($exception->errorCode)->toBe($code)
                ->and($exception->httpStatus)->toBe($status);
        }
    })->with([
        [400, 'content_policy_violation'],
        [401, 'invalid_api_key'],
        [404, 'not_found'],
        [409, 'idempotency_key_reused'],
        [422, 'validation_failed'],
        // A 429, but waiting a few seconds will not bring back today's quota.
        [429, 'daily_limit_exceeded'],
    ]);

    it('treats an unexpected server error as retryable', function () {
        Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 502)]);

        try {
            createTestGeneration($this->client);
            $this->fail('Expected a retryable exception.');
        } catch (PixelforgeRetryableException $exception) {
            expect($exception->errorCode)->toBe('unknown_error')
                ->and($exception->retryAfter)->toBeNull();
        }
    });

    it('treats a network failure as retryable', function () {
        Http::fake(['*' => Http::failedConnection()]);

        try {
            createTestGeneration($this->client);
            $this->fail('Expected a retryable exception.');
        } catch (PixelforgeRetryableException $exception) {
            expect($exception->errorCode)->toBe('connection_failed')
                ->and($exception->httpStatus)->toBeNull();
        }
    });
});

describe('usage', function () {
    it('reads what Pixelforge billed per UTC day as exact money', function () {
        Http::fake(['*/v1/usage*' => Http::response([
            'object' => 'usage',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
            'data' => [
                ['date' => '2026-11-01', 'generations' => 3, 'input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'cost_usd' => '0.169080000'],
                ['date' => '2026-11-02', 'generations' => 0, 'input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost_usd' => '0.000000000'],
            ],
        ])]);

        $days = $this->client->usage(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'));

        expect($days)->toHaveCount(2)
            ->and($days[0]->date)->toBe('2026-11-01')
            ->and($days[0]->generations)->toBe(3)
            ->and($days[0]->tokens->reasoning)->toBe(2950)
            ->and($days[0]->cost->toDecimal())->toBe('0.169080000')
            ->and($days[1]->cost->isZero())->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.promptedit.dev/v1/usage?start_date=2026-11-01&end_date=2026-11-02');
    });
});
