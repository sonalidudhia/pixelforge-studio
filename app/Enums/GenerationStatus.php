<?php

namespace App\Enums;

enum GenerationStatus: string
{
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
}
