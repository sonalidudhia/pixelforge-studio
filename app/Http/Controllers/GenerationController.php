<?php

namespace App\Http\Controllers;

use App\Actions\Generations\CreateGeneration;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Pixelforge\CostCalculator;
use App\Pixelforge\PricingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const int PER_PAGE = 12;

    /**
     * The studio: the generate form and the user's history.
     */
    public function index(Request $request, PricingRepository $pricing): Response
    {
        $pricing = $pricing->current();

        $generations = $request->user()->generations()
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'options' => [
                'sizes' => $pricing->sizes(),
                'qualities' => $pricing->qualities(),
                'max_prompt_characters' => 4000,
                'max_reference_images' => 4,
                'markup_percent' => (int) config('services.pixelforge.markup_percent'),
            ],
        ]);
    }

    /**
     * The price range for a request, shown live while the user types.
     */
    public function estimate(EstimateGenerationRequest $request, PricingRepository $pricing, CostCalculator $calculator): JsonResponse
    {
        $estimate = $calculator->estimate(
            promptCharacters: $request->integer('characters'),
            size: $request->string('size')->toString(),
            quality: $request->string('quality')->toString(),
            referenceImages: $request->integer('reference_images'),
            pricing: $pricing->current(),
        );

        return response()->json([
            'min' => $estimate->min->toDecimal(),
            'max' => $estimate->max->toDecimal(),
        ]);
    }

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

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to Pixelforge. It will appear below when it is ready.']);

        return to_route('generations.index');
    }
}
