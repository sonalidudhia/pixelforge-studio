<?php

namespace App\Pixelforge\Exceptions;

use Throwable;

/**
 * A temporary problem: a rate limit, the concurrency limit, an overload, a
 * server error or a network failure. Nothing was created or billed.
 */
final class PixelforgeRetryableException extends PixelforgeException
{
    /**
     * @param  int|null  $retryAfter  Seconds to wait, from the Retry-After header.
     */
    public function __construct(
        string $errorCode,
        string $message,
        ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $message, $httpStatus, $previous);
    }
}
