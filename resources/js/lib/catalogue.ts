export function kes(amount: string): string {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = (negative ? amount.slice(1) : amount).split(
        '.',
    );
    return `${negative ? '-' : ''}KES ${BigInt(whole).toLocaleString('en-KE')}.${fraction.padEnd(2, '0')}`;
}

export function quantity(amount: string, precision: number): string {
    return Number(amount).toFixed(precision);
}
