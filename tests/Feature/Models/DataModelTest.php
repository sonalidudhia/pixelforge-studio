<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Pixelforge\CostCalculator;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;

describe('generations', function () {
    it('stores money exactly, down to the nanodollar', function () {
        $generation = Generation::factory()->create([
            'estimated_max_cost' => Money::fromDecimal('0.000000001'),
        ]);

        expect($generation->fresh()->estimated_max_cost->nanos)->toBe(1);
    });

    it('stores more than a float could hold exactly', function () {
        // 2^53 + 1 nanodollars: a float would round this.
        $generation = Generation::factory()->create([
            'estimated_max_cost' => Money::fromNanos(9_007_199_254_740_993),
        ]);

        expect($generation->fresh()->estimated_max_cost->nanos)->toBe(9_007_199_254_740_993);
    });

    it('refuses a plain number as money', function () {
        Generation::factory()->create(['estimated_max_cost' => 0.45]);
    })->throws(InvalidArgumentException::class);

    it('reads status, rates and usage back as objects', function () {
        $generation = Generation::factory()->succeeded()->create()->fresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->billedRates()->output->toDecimal())->toBe('40.000000000')
            ->and($generation->tokenUsage()->reasoning)->toBe(3184)
            ->and($generation->reference_images)->toBe(['https://example.com/style.png']);
    });

    it('has no usage before it succeeds', function () {
        expect(Generation::factory()->processing()->create()->tokenUsage())->toBeNull()
            ->and(Generation::factory()->create()->billedRates())->toBeNull();
    });

    it('has factory costs that agree with the calculator', function () {
        $generation = Generation::factory()->succeeded()->create();
        $calculator = app(CostCalculator::class);

        $providerCost = $calculator->providerCost($generation->tokenUsage(), $generation->billedRates());

        expect($generation->provider_cost)->toEqual($providerCost)
            ->and($generation->user_cost)->toEqual($calculator->userPrice($providerCost));
    });

    it('never shares an idempotency key or a Pixelforge id', function (string $column) {
        $first = Generation::factory()->queued()->create();

        Generation::factory()->queued()->create([$column => $first->{$column}]);
    })->with(['idempotency_key', 'pixelforge_id'])
        ->throws(UniqueConstraintViolationException::class);

    it('belongs to a user', function () {
        $user = User::factory()->create();
        Generation::factory()->count(2)->for($user)->create();

        expect($user->generations)->toHaveCount(2);
    });
});

describe('webhook events', function () {
    it('stores each event id only once', function () {
        $event = ['event_id' => 'evt_1', 'type' => 'generation.succeeded', 'payload' => ['id' => 'evt_1']];

        WebhookEvent::create($event);
        WebhookEvent::create($event);
    })->throws(UniqueConstraintViolationException::class);
});

describe('credit transactions', function () {
    it('allows each kind of entry only once per generation', function () {
        $generation = Generation::factory()->create();
        $charge = ['generation_id' => $generation->id, 'type' => CreditTransactionType::Charge, 'amount' => Money::fromNanos(-5)];

        $generation->user->creditTransactions()->create($charge);
        $generation->user->creditTransactions()->create($charge);
    })->throws(UniqueConstraintViolationException::class);

    it('allows any number of purchases', function () {
        $user = User::factory()->create();
        $purchase = ['type' => CreditTransactionType::Purchase, 'amount' => Money::fromDecimal('10.00')];

        $user->creditTransactions()->create($purchase);
        $user->creditTransactions()->create($purchase);

        expect($user->creditTransactions()->sum('amount'))->toEqual(20_000_000_000);
    });

    it('stores negative amounts', function () {
        $generation = Generation::factory()->create();

        $hold = $generation->user->creditTransactions()->create([
            'generation_id' => $generation->id,
            'type' => CreditTransactionType::Hold,
            'amount' => Money::zero()->minus(Money::fromDecimal('0.457150000')),
        ]);

        expect($hold->fresh()->amount->toDecimal())->toBe('-0.457150000');
    });
});
