<?php

use App\Enums\GenerationStatus;

it('knows which statuses are final', function () {
    expect(GenerationStatus::Pending->isFinal())->toBeFalse()
        ->and(GenerationStatus::Queued->isFinal())->toBeFalse()
        ->and(GenerationStatus::Processing->isFinal())->toBeFalse()
        ->and(GenerationStatus::Succeeded->isFinal())->toBeTrue()
        ->and(GenerationStatus::Failed->isFinal())->toBeTrue();
});

it('moves forward', function (GenerationStatus $from, GenerationStatus $to) {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    [GenerationStatus::Pending, GenerationStatus::Queued],
    [GenerationStatus::Pending, GenerationStatus::Processing],
    [GenerationStatus::Pending, GenerationStatus::Succeeded],
    // Rejected before Pixelforge ever accepted it.
    [GenerationStatus::Pending, GenerationStatus::Failed],
    [GenerationStatus::Queued, GenerationStatus::Processing],
    // The processing webhook can be lost or arrive late.
    [GenerationStatus::Queued, GenerationStatus::Succeeded],
    [GenerationStatus::Queued, GenerationStatus::Failed],
    [GenerationStatus::Processing, GenerationStatus::Succeeded],
    [GenerationStatus::Processing, GenerationStatus::Failed],
]);

it('never moves backwards or sideways', function (GenerationStatus $from, GenerationStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    // A late or repeated webhook.
    [GenerationStatus::Succeeded, GenerationStatus::Processing],
    [GenerationStatus::Succeeded, GenerationStatus::Queued],
    [GenerationStatus::Failed, GenerationStatus::Processing],
    [GenerationStatus::Processing, GenerationStatus::Queued],
    // Same status again: nothing to do.
    [GenerationStatus::Processing, GenerationStatus::Processing],
    [GenerationStatus::Succeeded, GenerationStatus::Succeeded],
    // A final status never flips to the other final status.
    [GenerationStatus::Succeeded, GenerationStatus::Failed],
    [GenerationStatus::Failed, GenerationStatus::Succeeded],
]);
