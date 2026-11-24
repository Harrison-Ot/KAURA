export type Category = { id: number; name: string };

export type Product = {
    id: number;
    sku: string;
    name: string;
    category_id: number;
    category_name: string;
    unit_label: string;
    quantity_precision: number;
    selling_price: string;
    purchase_cost?: string | null;
    average_cost?: string | null;
    inventory_value?: string | null;
    quantity_on_hand: string;
    reorder_threshold: string;
    barcode: string | null;
    is_active: boolean;
    version: number;
};

export type Customer = {
    id: number;
    name: string;
    contact: string;
    credit_limit?: string;
    version: number;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
