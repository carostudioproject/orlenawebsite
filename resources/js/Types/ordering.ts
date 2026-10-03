export interface OrderItem { id: number; product_name_snapshot: string; category_snapshot: string; variant_snapshot: string | null; quantity: number; unit_price_snapshot: number; subtotal: number }
export interface OrderCategory { id: number; name: string; image: string | null }
export interface OrderProduct { id: number; name: string; category_id: number; description: string | null; image: string | null; variant: string | null; is_hamper: boolean; hamper_items: string[]; sale_ends_on: string | null; price: number }
export type OrderStatus = 'pending_review' | 'confirmed' | 'processing' | 'ready' | 'delivering' | 'completed' | 'cancelled';
export type PaymentStatus = 'not_created' | 'pending' | 'paid' | 'failed' | 'expired' | 'cancelled' | 'refunded';
export interface Preorder {
    review_version: number;
    id: number; order_code: string; customer: { name: string; whatsapp: string; email: string | null };
    items: OrderItem[]; outlet_name_snapshot: string; fulfillment_method: 'pickup' | 'delivery'; requested_date: string;
    requested_time: string | null; delivery_address: string | null; customer_note: string | null; card_message?: string | null;
    subtotal: number; delivery_fee: number | null; total: number; order_status: OrderStatus; payment_status: PaymentStatus; created_at: string;
}
export interface PaymentAttempt {
    id: number; attempt: number; provider?: string; status: PaymentStatus | 'creating' | 'creation_failed'; amount: number; payment_url: string | null;
    payment_type: string | null; expires_at: string | null; paid_at: string | null; reason: string | null; last_error: string | null;
    created_at: string; creator: { id: number; name: string } | null;
}
export interface StatusChange { id: number; from_status: string | null; to_status: string; note: string | null; actor_name: string | null; created_at: string }
