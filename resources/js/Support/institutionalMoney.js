/** Exact centavo strings only. Never convert financial aggregates to Number. */
export function formatInstitutionalMoney(cents, currency = 'MXN') {
    if (typeof cents !== 'string' || !/^-?(0|[1-9][0-9]*)$/.test(cents) || !/^[A-Z]{3}$/.test(currency)) return 'Importe no disponible';
    const value = BigInt(cents);
    const absolute = value < 0n ? -value : value;
    const units = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 }).format(absolute / 100n);
    return `${currency} ${value < 0n ? '-' : ''}${units}.${String(absolute % 100n).padStart(2, '0')}`;
}
