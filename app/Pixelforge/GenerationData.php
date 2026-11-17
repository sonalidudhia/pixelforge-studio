<?php

namespace App\Pixelforge;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;

/**
 * Pixelforge's generation object, as it was at one moment in time.
 *
 * The same object comes back from POST /generations, GET /generations/{id}
 * and inside every webhook event, so all three are read with this class.
 */
final readonly class GenerationData
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public Rates $rates,
        public ?TokenUsage $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $metadata,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $generation
     */
    public static function fromApi(array $generation): self
    {
        return new self(
            id: $generation['id'],
            status: GenerationStatus::from($generation['status']),
            rates: Rates::fromApi($generation['rates']),
            usage: isset($generation['usage']) ? TokenUsage::fromApi($generation['usage']) : null,
            imageUrl: $generation['image_url'] ?? null,
            imageUrlExpiresAt: self::date($generation['image_url_expires_at'] ?? null),
            errorCode: $generation['error']['code'] ?? null,
            errorMessage: $generation['error']['message'] ?? null,
            metadata: $generation['metadata'] ?? [],
            createdAt: CarbonImmutable::parse($generation['created_at']),
            startedAt: self::date($generation['started_at'] ?? null),
            completedAt: self::date($generation['completed_at'] ?? null),
        );
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
