export type BatchKind = 'opening_stock' | 'opening_debt' | 'stock_receipt';
export type InventoryBatch = {
    id: number;
    reference: string;
    kind: BatchKind;
    status: 'draft' | 'posted' | 'cancelled';
    effective_on: string;
    source_note: string;
    total: string | null;
    amount_paid: string | null;
    supplier_name: string | null;
    delivery_reference: string | null;
    payment_method: string | null;
    payment_reference: string | null;
    posted_at: string | null;
    decision_reason: string | null;
};
export const batchLabels: Record<BatchKind, string> = {
    opening_stock: 'Opening stock',
    opening_debt: 'Opening debt',
    stock_receipt: 'Stock delivery',
};
