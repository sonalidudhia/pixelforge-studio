<?php

namespace App\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Any failed call to Pixelforge. There are exactly two kinds:
 *
 *  - PixelforgeRetryableException: nothing was created, trying again can work.
 *  - PixelforgeRejectedException:  trying again with the same request won't help.
 */
abstract class PixelforgeException extends RuntimeException
{
    /**
     * @param  string  $errorCode  Pixelforge's error code, e.g. "overloaded".
     * @param  int|null  $httpStatus  Null when no response arrived at all.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
