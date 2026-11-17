<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Defaults match the high quality 1536x1024 example in the Pixelforge docs.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'pixelforge_id' => null,
            'status' => GenerationStatus::Pending,
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/style.png'],
            'estimated_min_cost' => Money::fromDecimal('0.347750000'),
            'estimated_max_cost' => Money::fromDecimal('0.457150000'),
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'pixelforge_id' => 'gen_'.Str::upper(Str::random(26)),
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'submit_attempts' => 1,
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn (): array => [
            'status' => GenerationStatus::Processing,
            'started_at' => now(),
        ]);
    }

    /**
     * Usage from the docs: 12 text, 765 image, 6,450 output, 3,184 reasoning tokens.
     */
    public function succeeded(): static
    {
        return $this->processing()->state(fn (): array => [
            'status' => GenerationStatus::Succeeded,
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'provider_cost' => Money::fromDecimal('0.305510000'),
            'user_cost' => Money::fromDecimal('0.381887500'),
            'image_url' => 'https://pixelforge.promptedit.dev/images/example.png',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->processing()->state(fn (): array => [
            'status' => GenerationStatus::Failed,
            'provider_cost' => Money::zero(),
            'user_cost' => Money::zero(),
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image for this prompt.',
            'completed_at' => now(),
        ]);
    }
}
