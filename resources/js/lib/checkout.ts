export function minorAmount(value: string): bigint | null {
    if (!/^\d{1,14}(?:\.\d{1,2})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}

export function scaledQuantity(
    value: string,
    precision: number,
): bigint | null {
    const pattern =
        precision === 0
            ? /^\d{1,9}$/
            : new RegExp(`^\\d{1,9}(?:\\.\\d{1,${precision}})?$`);
    if (!pattern.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 1000n + BigInt(fraction.padEnd(3, '0'));
}

export function decimalAmount(minor: bigint): string {
    return `${minor / 100n}.${(minor % 100n).toString().padStart(2, '0')}`;
}

export function lineAmount(
    quantity: string,
    precision: number,
    price: string,
): bigint | null {
    const units = scaledQuantity(quantity, precision);
    const minor = minorAmount(price);
    if (units === null || units <= 0n || minor === null) return null;
    return (units * minor + 500n) / 1000n;
}
