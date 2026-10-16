export interface OrderItem { id: number; parent_item_id?: number | null; product_name_snapshot: string; category_snapshot: string; variant_snapshot: string | null; quantity: number; unit_price_snapshot: number; subtotal: number; input_label_snapshot?: string | null; input_texts?: string[] | null }
/** A text the customer writes on a product's cart line, e.g. a greeting card or the writing on a cake. */
export interface CustomerInput { label: string; placeholder: string | null; required: boolean; max: number }
/** addon_ids: extras (paper bag, candle, card…) offered in the popup of this category's products. */
export interface OrderCategory { id: number; name: string; image: string | null; addon_ids?: number[] }
/** A content the customer can choose for a hampers/box; price is what it adds per unit (0 in a box). */
export interface ContentOption { id: number; name: string; variant: string | null; image: string | null; price: number }
export interface OrderProduct {
    id: number; name: string; category_id: number; description: string | null; image: string | null; variant: string | null; is_hamper: boolean; input?: CustomerInput | null; featured?: boolean; featured_label?: string | null; hamper_items: string[]; sale_ends_on: string | null; price: number;
    content_type: 'hampers' | 'box' | 'set' | null; content_count: number | null; contents: ContentOption[];
    /** A box with fixed flavours: what each unit holds. */
    fixed_contents: { id: number; name: string; variant: string | null; image: string | null; quantity: number }[];
}
export type OrderStatus = 'pending_review' | 'confirmed' | 'processing' | 'ready' | 'delivering' | 'completed' | 'cancelled';
export type PaymentStatus = 'not_created' | 'pending' | 'paid' | 'failed' | 'expired' | 'cancelled' | 'refunded';
export interface Preorder {
    review_version: number;
    id: number; order_code: string; customer: { name: string; whatsapp: string; email: string | null };
    items: OrderItem[]; outlet_name_snapshot: string; fulfillment_method: 'pickup' | 'delivery'; requested_date: string;
    requested_time: string | null; delivery_address: string | null; recipient_whatsapp?: string | null; location_url?: string | null; outlet_id?: number; nearest_outlet?: boolean; customer_note: string | null; card_message?: string | null;
    subtotal: number; delivery_fee: number | null; total: number; order_status: OrderStatus; payment_status: PaymentStatus; payment_due_at?: string | null; moved_from_date?: string | null; rescheduled_from?: string | null; rescheduled_at?: string | null; erzap_voided_at?: string | null; created_at: string;
}
export interface PaymentAttempt {
    id: number; attempt: number; provider?: string; method?: string | null; status: PaymentStatus | 'creating' | 'creation_failed'; amount: number; payment_url: string | null;
    payment_type: string | null; expires_at: string | null; paid_at: string | null; reason: string | null; last_error: string | null;
    created_at: string; creator: { id: number; name: string } | null;
}
export interface StatusChange { id: number; from_status: string | null; to_status: string; note: string | null; actor_name: string | null; created_at: string }
