/**
 * Format an amount given in minor units (e.g. cents) as a localized currency
 * string. Divides by 100 to convert minor units to major units.
 */
export function money(amountMinorUnits: number, currency: string): string {
    const amount = amountMinorUnits / 100;

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
        }).format(amount);
    } catch {
        // Fall back gracefully if the currency code is unknown/unsupported.
        return `${currency} ${amount.toFixed(2)}`;
    }
}

/**
 * Format a byte count into a human-readable size (B, KB, MB, GB, TB).
 */
export function bytes(value: number): string {
    if (!Number.isFinite(value) || value <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(
        Math.floor(Math.log(value) / Math.log(1024)),
        units.length - 1,
    );
    const size = value / Math.pow(1024, exponent);
    const rounded = exponent === 0 ? size : Math.round(size * 10) / 10;

    return `${rounded} ${units[exponent]}`;
}
