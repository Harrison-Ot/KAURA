import { kes } from '@/lib/catalogue';

export function decimalMinor(value: string | number): string {
    const amount = BigInt(value);
    const absolute = amount < 0n ? -amount : amount;
    return `${amount < 0n ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
}
export function minorMoney(value: string | number): string {
    return kes(decimalMinor(value));
}
