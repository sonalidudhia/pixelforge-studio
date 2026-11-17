<?php

namespace App\Http\Requests;

use App\Pixelforge\PricingRepository;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The live price estimate only needs the prompt's length, not its text.
 */
class EstimateGenerationRequest extends FormRequest
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
            'characters' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', 'string', Rule::in($pricing->sizes())],
            'quality' => ['required', 'string', Rule::in($pricing->qualities())],
            'reference_images' => ['required', 'integer', 'min:0', 'max:4'],
        ];
    }
}
