<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    fakePixelforgePricing();

    $this->user = User::factory()->create();
});

describe('studio page', function () {
    it('requires signing in', function () {
        $this->get(route('generations.index'))->assertRedirect(route('login'));
    });

    it('shows the options Pixelforge sells', function () {
        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('generations/Index')
                ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('options.qualities', ['low', 'medium', 'high'])
                ->where('options.markup_percent', 25)
            );
    });

    it('lists only the user\'s own generations, newest first', function () {
        $older = Generation::factory()->for($this->user)->succeeded()->create();
        $newer = Generation::factory()->for($this->user)->create();
        Generation::factory()->succeeded()->create();

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 2)
                ->where('generations.data.0.id', $newer->id)
                ->where('generations.data.1.id', $older->id)
                ->where('generations.meta.total', 2)
            );
    });

    it('sends money as exact strings and keeps our provider cost private', function () {
        Generation::factory()->for($this->user)->succeeded()->create();

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.status', 'succeeded')
                ->where('generations.data.0.cost', '0.381887500')
                ->where('generations.data.0.estimated_min_cost', '0.347750000')
                ->where('generations.data.0.estimated_max_cost', '0.457150000')
                ->where('generations.data.0.tokens.reasoning', 3184)
                ->missing('generations.data.0.provider_cost')
            );
    });

    it('shows a failed generation as free with its reason', function () {
        Generation::factory()->for($this->user)->failed()->create();

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.status', 'failed')
                ->where('generations.data.0.cost', '0.000000000')
                ->where('generations.data.0.error_message', 'The model could not produce an image for this prompt.')
            );
    });

    it('hides an image link once Pixelforge has expired it', function () {
        Generation::factory()->for($this->user)->succeeded()->create(['image_url_expires_at' => now()->subMinute()]);

        $this->actingAs($this->user)
            ->get(route('generations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generations.data.0.image_url', null)
                ->where('generations.data.0.image_expired', true)
            );
    });

    it('pages through long histories', function () {
        Generation::factory()->for($this->user)->count(13)->create();

        $this->actingAs($this->user)
            ->get(route('generations.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('generations.data', 1)
                ->where('generations.meta.last_page', 2)
            );
    });
});

describe('estimate endpoint', function () {
    it('prices a request from its prompt length', function () {
        $this->actingAs($this->user)
            ->getJson(route('generations.estimate', [
                'characters' => 400,
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_images' => 2,
            ]))
            ->assertOk()
            // The worked example from the Pixelforge docs, plus 25%.
            ->assertExactJson(['min' => '0.357781250', 'max' => '0.467343750']);
    });

    it('agrees with the estimate stored when submitting', function () {
        Queue::fake();
        $prompt = 'A lighthouse on a cliff at dawn, oil painting';

        $this->actingAs($this->user)->post(route('generations.store'), [
            'prompt' => $prompt,
            'size' => '1024x1536',
            'quality' => 'medium',
            'reference_images' => ['https://example.com/a.png'],
        ]);

        $stored = $this->user->generations()->sole();

        $this->actingAs($this->user)
            ->getJson(route('generations.estimate', [
                'characters' => mb_strlen($prompt),
                'size' => '1024x1536',
                'quality' => 'medium',
                'reference_images' => 1,
            ]))
            ->assertExactJson([
                'min' => $stored->estimated_min_cost->toDecimal(),
                'max' => $stored->estimated_max_cost->toDecimal(),
            ]);
    });

    it('refuses impossible requests', function (array $query, string $field) {
        $this->actingAs($this->user)
            ->getJson(route('generations.estimate', array_replace([
                'characters' => 10, 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => 0,
            ], $query)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        [['characters' => 4001], 'characters'],
        [['size' => '512x512'], 'size'],
        [['quality' => 'ultra'], 'quality'],
        [['reference_images' => 5], 'reference_images'],
    ]);

    it('requires signing in', function () {
        $this->getJson(route('generations.estimate'))->assertUnauthorized();
    });
});

it('sends the user back to the studio with a confirmation after submitting', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->post(route('generations.store'), [
            'prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low',
        ])
        ->assertRedirect(route('generations.index'));
});

it('does not let live estimates use up the submit limit', function () {
    Queue::fake();

    // Typing a prompt fires many estimate requests.
    foreach (range(1, 30) as $i) {
        $this->actingAs($this->user)
            ->getJson(route('generations.estimate', [
                'characters' => $i, 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => 0,
            ]))
            ->assertOk();
    }

    $this->actingAs($this->user)
        ->post(route('generations.store'), ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertRedirect(route('generations.index'));
});

it('still limits submits to 10 a minute per user', function () {
    Queue::fake();
    $submit = fn () => $this->actingAs($this->user)
        ->post(route('generations.store'), ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low']);

    foreach (range(1, 10) as $i) {
        $submit()->assertRedirect();
    }

    $submit()->assertTooManyRequests();
});
