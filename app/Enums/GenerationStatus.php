<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Ours only: saved, but Pixelforge has not accepted it yet. */
    case Pending = 'pending';

    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Succeeded and failed are final: Pixelforge never changes them again.
     */
    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Webhooks can arrive late and out of order, so a generation only ever
     * moves forward: a late "processing" must never undo "succeeded".
     */
    public function canTransitionTo(self $next): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        return $next->step() > $this->step();
    }

    private function step(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}
