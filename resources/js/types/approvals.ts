export type Approval = {
    id: number;
    requested_by: number;
    requested_by_name: string;
    customer_id: number | null;
    customer_snapshot: { name: string; contact: string } | null;
    items: {
        product_id: number;
        quantity: string;
        quoted_price: string;
        name: string;
        sku: string;
        unit_label: string;
    }[];
    payment_method: 'cash' | 'manual_mpesa';
    due_on: string | null;
    reason: string;
    decision_reason: string | null;
    checkout_key: string;
    expires_at: string | null;
    consumed_sale_id: number | null;
    status: string;
    gross: string;
    discount: string;
    credit: string;
    total: string;
    deposit: string;
};
