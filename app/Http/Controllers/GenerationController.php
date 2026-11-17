<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use Illuminate\Http\RedirectResponse;

class GenerationController extends Controller
{
    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images?: array<int, string>} $input */
        $input = $request->validated();

        $createGeneration->handle(
            user: $request->user(),
            prompt: $input['prompt'],
            size: $input['size'],
            quality: $input['quality'],
            referenceImages: array_values($input['reference_images'] ?? []),
        );

        return back();
    }
}
