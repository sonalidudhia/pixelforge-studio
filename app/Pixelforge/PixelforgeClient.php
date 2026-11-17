<?php

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\PixelforgeRejectedException;
use App\Pixelforge\Exceptions\PixelforgeRetryableException;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The only class that talks to Pixelforge's authenticated endpoints.
 *
 * It makes one attempt per call and never retries by itself. A failure
 * becomes a PixelforgeRetryableException or a PixelforgeRejectedException,
 * and the caller (a queued job) decides when to try again.
 */
final readonly class PixelforgeClient
{
    /** These 429s clear up by themselves; daily_limit_exceeded does not. */
    private const array RETRYABLE_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private Http $http,
        private string $baseUrl,
        private string $apiKey,
    ) {}

    /**
     * Start a generation. Sending the same idempotency key again returns the
     * same generation instead of creating (and billing) a second one.
     *
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     */
    public function createGeneration(
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages,
        array $metadata,
        string $idempotencyKey,
    ): GenerationData {
        $body = [
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
        ];

        if ($referenceImages !== []) {
            $body['reference_images'] = $referenceImages;
        }

        if ($metadata !== []) {
            $body['metadata'] = $metadata;
        }

        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('generations', $body));

        return GenerationData::fromApi($response->json());
    }

    public function getGeneration(string $id): GenerationData
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get("generations/{$id}"));

        return GenerationData::fromApi($response->json());
    }

    /**
     * What Pixelforge billed this API key on each UTC day, both dates included.
     *
     * @return list<DailyUsage>
     */
    public function usage(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('usage', [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ]));

        return array_values(array_map(DailyUsage::fromApi(...), $response->json('data')));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $request = $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT_SECONDS);

        try {
            $response = $call($request);
        } catch (ConnectionException $exception) {
            // No answer at all. The request may or may not have arrived, which
            // is exactly what the idempotency key makes safe to retry.
            throw new PixelforgeRetryableException(
                errorCode: 'connection_failed',
                message: 'Could not reach Pixelforge.',
                previous: $exception,
            );
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->exceptionFor($response);
    }

    private function exceptionFor(Response $response): PixelforgeRetryableException|PixelforgeRejectedException
    {
        $errorCode = (string) $response->json('error.code', 'unknown_error');
        $message = (string) $response->json('error.message', "Pixelforge answered with HTTP {$response->status()}.");

        if (in_array($errorCode, self::RETRYABLE_CODES, true) || $response->serverError()) {
            $retryAfter = $response->header('Retry-After');

            return new PixelforgeRetryableException(
                errorCode: $errorCode,
                message: $message,
                httpStatus: $response->status(),
                retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        return new PixelforgeRejectedException($errorCode, $message, $response->status());
    }
}
