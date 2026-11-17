<?php

namespace App\Http\Requests;

use App\Pixelforge\PricingRepository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The same limits Pixelforge applies, checked before anything is queued.
 */
class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(PricingRepository $pricing): array
    {
        $pricing = $pricing->current();

        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', 'string', Rule::in($pricing->sizes())],
            'quality' => ['required', 'string', Rule::in($pricing->qualities())],
            'reference_images' => ['sometimes', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be an http or https URL.',
        ];
    }
}
