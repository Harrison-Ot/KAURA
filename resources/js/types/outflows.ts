export type Outflow = {
    id: number;
    reference: string;
    kind: 'stock_purchase' | 'expense' | 'owner_withdrawal' | 'refund';
    void_sale_id: number | null;
    category: string | null;
    amount: string;
    payment_method: 'cash' | 'mpesa';
    payment_reference: string | null;
    recipient: string | null;
    reason: string | null;
    effective_on: string;
    created_at: string;
    actor_name: string;
    batch_id: number | null;
    batch_reference: string | null;
};
export const outflowLabels = {
    refund: 'Customer refund',
    stock_purchase: 'Stock purchase',
    expense: 'Operating expense',
    owner_withdrawal: 'Owner withdrawal',
};
