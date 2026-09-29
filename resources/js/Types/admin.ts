export type StaffRole = 'admin' | 'staff' | 'finance' | 'content_editor';
export interface StaffUser { id: number; name: string; username: string; email: string | null; role: StaffRole; role_label?: string; is_active: boolean }
export interface Abilities { orders: boolean; review: boolean; catalog: boolean; manageCatalog: boolean; users: boolean; content: boolean; reports: boolean; schedule: boolean; integrations: boolean }
export interface AdminProps { auth: { user: StaffUser; can_manage: boolean; can: Abilities }; flash: { success?: string }; poCutoff: string; [key: string]: unknown }
export const roleOptions: { value: StaffRole; label: string; description: string }[] = [
    { value: 'admin', label: 'Admin', description: 'Full access, including team accounts' },
    { value: 'staff', label: 'Staff', description: 'Processes orders and views the catalog' },
    { value: 'finance', label: 'Finance', description: 'Views orders, payments and reports (read only)' },
    { value: 'content_editor', label: 'Content Editor', description: 'Manages homepage, About and blog content' },
];
export interface PageLink { url: string | null; label: string; active: boolean }
export interface Paginated<T> { data: T[]; links: PageLink[]; total: number; from: number | null; to: number | null }
export interface CatalogRecord {
    id: number; name: string; sku?: string; code?: string; category_id?: number; category?: { name: string };
    price?: number | null; is_active?: boolean; accepts_preorder?: boolean; is_delivery_hub?: boolean; show_on_website?: boolean; variant?: string | null; is_hamper?: boolean; hamper_contents?: string | null; sale_starts_on?: string | null; sale_ends_on?: string | null;
    erzap_product_id?: string | null; erzap_variant_id?: string | null; barcode?: string | null; erzap_outlet_id?: string | null; image?: string | null; description?: string | null; address?: string; maps_url?: string | null;
    outlet_prices?: { outlet_id: number; price: number }[]; order_position?: number;
}
