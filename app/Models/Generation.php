<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\GenerationStatus;
use App\Pixelforge\Rates;
use App\Pixelforge\TokenUsage;
use App\Support\Money;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property Money $estimated_min_cost
 * @property Money $estimated_max_cost
 * @property array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}|null $rates
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property Money|null $provider_cost
 * @property Money|null $user_cost
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'idempotency_key',
    'pixelforge_id',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'estimated_min_cost',
    'estimated_max_cost',
    'rates',
    'input_text_tokens',
    'input_image_tokens',
    'output_tokens',
    'reasoning_tokens',
    'provider_cost',
    'user_cost',
    'image_url',
    'image_url_expires_at',
    'error_code',
    'error_message',
    'submit_attempts',
    'started_at',
    'completed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The rates Pixelforge fixed for this generation, once it has accepted it.
     */
    public function billedRates(): ?Rates
    {
        return $this->rates === null ? null : Rates::fromApi($this->rates);
    }

    /**
     * The tokens Pixelforge reported, once the generation has succeeded.
     */
    public function tokenUsage(): ?TokenUsage
    {
        if ($this->output_tokens === null) {
            return null;
        }

        return new TokenUsage(
            inputText: (int) $this->input_text_tokens,
            inputImage: (int) $this->input_image_tokens,
            output: $this->output_tokens,
            reasoning: (int) $this->reasoning_tokens,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'estimated_min_cost' => MoneyCast::class,
            'estimated_max_cost' => MoneyCast::class,
            'provider_cost' => MoneyCast::class,
            'user_cost' => MoneyCast::class,
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
