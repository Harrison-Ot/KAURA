export type SaleSummary = {
    id: number;
    reference: string;
    status: string;
    employee_name: string;
    business_date: string;
    sold_at: string;
    total: string;
    checkout_method: 'cash' | 'manual_mpesa';
    settlement: {
        confirmed: string;
        pending: string;
        disputed: string;
        balance_due: string;
        state:
            | 'paid'
            | 'pending_verification'
            | 'disputed'
            | 'credit_due'
            | 'voided';
    };
};
export type SaleDetails = SaleSummary & {
    void: {
        resolution: {
            credit_payout_amount: string | null;
            kind: 'refund' | 'customer_credit';
            amount: string;
            business_date: string;
        } | null;
        id: number;
        reason: string;
        actor_name: string;
        created_at: string;
        stock_status: string;
        stock_reason: string | null;
        funds_status: string;
        confirmed_funds: string;
        canceled_debt: string;
    } | null;
    shop_snapshot: {
        shop_name: string;
        address: string | null;
        contact: string | null;
    };
    customer_snapshot: { name: string; contact: string } | null;
    cash_tendered: string;
    change: string;
    gross: string;
    discount: string;
    cost_total?: string | null;
    approved_credit: string;
    approval_id: number | null;
    promised_due_on: string | null;
    items: {
        id: number;
        sku: string;
        name: string;
        unit_label: string;
        quantity: string;
        quantity_precision: number;
        unit_price: string;
        total: string;
        discount: string;
        unit_cost?: string | null;
        cost_total?: string | null;
    }[];
    payments: {
        id: number;
        reference: string;
        method: string;
        status: string;
        amount: string;
        mpesa_code: string | null;
        receiving_number: string | null;
        payer_reference: string | null;
    }[];
};
export type TransactionFilters = {
    from: string;
    to: string;
    employee_id: number | '';
    status?: 'all' | 'pending' | 'verified' | 'disputed';
};
