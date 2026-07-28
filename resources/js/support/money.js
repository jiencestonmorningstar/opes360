/**
 * Client-side money formatting.
 *
 * Deliberately mirrors App\Support\Money so a total computed offline reads
 * exactly like the same total rendered by the server — a figure that changes
 * appearance when a document syncs would look like the amount had changed.
 */

const SYMBOLS = {
    USD: '$',
    EUR: '€',
    GBP: '£',
    NGN: '₦',
    GHS: 'GH₵',
    KES: 'KSh',
    ZAR: 'R',
    XOF: 'CFA',
};

export function symbol(currency) {
    const code = (currency || 'USD').toUpperCase();

    return SYMBOLS[code] ?? `${code} `;
}

export function money(amount, currency = 'USD', decimals = true) {
    const value = Number(amount) || 0;

    return symbol(currency) + value.toLocaleString('en-US', {
        minimumFractionDigits: decimals ? 2 : 0,
        maximumFractionDigits: decimals ? 2 : 0,
    });
}
