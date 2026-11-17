<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A US dollar amount stored as a whole number of nanodollars.
 *
 * 1 USD = 1,000,000,000 nanodollars. Pixelforge reports money with nine
 * decimal places, so every amount it can send fits exactly. All maths is
 * integer maths; floats are never used.
 */
final readonly class Money
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private const int DECIMAL_PLACES = 9;

    private function __construct(public int $nanos) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromNanos(int $nanos): self
    {
        return new self($nanos);
    }

    /**
     * Parse an exact decimal string such as "0.046125000" or "5.00".
     */
    public static function fromDecimal(string $amount): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException("Not a valid dollar amount: [{$amount}].");
        }

        $dollars = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', self::DECIMAL_PLACES, '0');

        return new self($dollars * self::NANOS_PER_DOLLAR + $fraction);
    }

    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    public function isZero(): bool
    {
        return $this->nanos === 0;
    }

    /**
     * The exact amount with nine decimal places, e.g. "0.373875000".
     */
    public function toDecimal(): string
    {
        $sign = $this->nanos < 0 ? '-' : '';
        $absolute = abs($this->nanos);

        return sprintf(
            '%s%d.%09d',
            $sign,
            intdiv($absolute, self::NANOS_PER_DOLLAR),
            $absolute % self::NANOS_PER_DOLLAR,
        );
    }
}
