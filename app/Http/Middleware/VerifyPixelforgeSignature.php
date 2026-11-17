<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a webhook through only if Pixelforge signed it.
 *
 * Header: "Pixelforge-Signature: t=<unix timestamp>,v1=<hex signature>", where
 * the signature is HMAC-SHA256 of "<timestamp>.<raw body>" with our key's
 * signing secret. Old timestamps are refused so a captured request can't be
 * replayed later.
 */
class VerifyPixelforgeSignature
{
    public const int TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');
        $signature = $this->parseHeader((string) $request->header('Pixelforge-Signature'));

        $timestamp = (int) ($signature['t'] ?? 0);
        $given = $signature['v1'] ?? '';

        // Sign the raw body exactly as received, before any JSON decoding.
        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        $valid = $secret !== ''
            && hash_equals($expected, $given)
            && abs(time() - $timestamp) <= self::TOLERANCE_SECONDS;

        if (! $valid) {
            return response()->json(['error' => 'Invalid Pixelforge signature.'], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }

    /**
     * @return array<string, string>
     */
    private function parseHeader(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        return $parts;
    }
}
