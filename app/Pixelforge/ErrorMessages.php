<?php

namespace App\Pixelforge;

/**
 * Plain-language explanations of Pixelforge error codes, for the user.
 */
final class ErrorMessages
{
    public static function forUser(string $errorCode, string $fallback): string
    {
        return match ($errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt. Try wording it differently.',
            'daily_limit_exceeded' => 'The daily image limit has been reached. Try again after midnight UTC.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded' => 'Pixelforge stayed too busy to take this image, even after several tries. Please try again in a minute.',
            'connection_failed' => 'We could not reach Pixelforge, even after several tries. Please try again in a minute.',
            'invalid_api_key' => 'Image generation is not set up correctly on our side. We have been notified.',
            default => $fallback,
        };
    }
}
