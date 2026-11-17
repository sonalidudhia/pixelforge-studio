<?php

namespace App\Pixelforge\Exceptions;

/**
 * Pixelforge refused the request and will refuse it again: a blocked prompt,
 * invalid parameters, a bad API key, or the daily quota being used up.
 */
final class PixelforgeRejectedException extends PixelforgeException {}
