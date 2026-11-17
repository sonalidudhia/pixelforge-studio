<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as the user sees it.
 *
 * Money goes out as exact decimal strings ("0.020465625"), never floats, so
 * the browser can't round it. Our provider cost is internal and not sent.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $usage = $this->tokenUsage();
        $imageExpired = $this->image_url_expires_at?->isPast() ?? false;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_min_cost' => $this->estimated_min_cost->toDecimal(),
            'estimated_max_cost' => $this->estimated_max_cost->toDecimal(),
            'cost' => $this->user_cost?->toDecimal(),
            'tokens' => $usage === null ? null : [
                'input_text' => $usage->inputText,
                'input_image' => $usage->inputImage,
                'output' => $usage->output,
                'reasoning' => $usage->reasoning,
            ],
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            'error_message' => $this->error_message,
            'submit_attempts' => $this->submit_attempts,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
