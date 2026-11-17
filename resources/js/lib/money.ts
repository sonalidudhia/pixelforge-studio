/**
 * Formatting for the exact decimal strings the server sends ("0.020465625").
 *
 * All maths is done on whole nanodollars with BigInt, never with floats, so
 * what the user sees is exactly what was recorded.
 */

const DECIMALS = 9;
const NANOS_PER_DOLLAR = 10n ** BigInt(DECIMALS);

function toNanos(decimal: string): bigint {
    const [whole, fraction = ''] = decimal.split('.');

    return (
        BigInt(whole) * NANOS_PER_DOLLAR +
        BigInt(fraction.padEnd(DECIMALS, '0').slice(0, DECIMALS))
    );
}

function fromNanos(nanos: bigint, decimals: number): string {
    const whole = nanos / NANOS_PER_DOLLAR;
    const fraction = (nanos % NANOS_PER_DOLLAR)
        .toString()
        .padStart(DECIMALS, '0')
        .slice(0, decimals);

    return decimals > 0 ? `${whole}.${fraction}` : `${whole}`;
}

/**
 * The exact amount, without trailing zeros: "$0.020465625", "$0.3818875", "$0.00".
 */
export function formatExactUsd(decimal: string): string {
    const exact = fromNanos(toNanos(decimal), DECIMALS);
    const [whole, fraction] = exact.split('.');
    const trimmed = fraction.replace(/0+$/, '').padEnd(2, '0');

    return `$${whole}.${trimmed}`;
}

/**
 * Rounded to a few decimals, in a chosen direction, so that a range shown as
 * "$0.0140 – $0.0219" always contains every price it stands for.
 */
export function formatRoundedUsd(
    decimal: string,
    decimals: number,
    direction: 'up' | 'down',
): string {
    const step = 10n ** BigInt(DECIMALS - decimals);
    const nanos = toNanos(decimal);
    const remainder = nanos % step;
    const rounded =
        remainder === 0n || direction === 'down'
            ? nanos - remainder
            : nanos - remainder + step;

    return `$${fromNanos(rounded, decimals)}`;
}

export function formatRange(min: string, max: string): string {
    return `${formatRoundedUsd(min, 4, 'down')} – ${formatRoundedUsd(max, 4, 'up')}`;
}
