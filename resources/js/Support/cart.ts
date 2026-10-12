import type { OrderProduct } from '../Types/ordering';

/** One cart line: a product, how many, and for hampers/box the contents chosen for one unit (product IDs, repeats allowed). */
export interface CartLine { product_id: number; quantity: number; contents: number[] }

export const MAX_LINES = 50;
export const clampQuantity = (value: number) => Math.min(99, Math.max(1, Math.round(value) || 1));

const sameContents = (a: number[], b: number[]) => a.length === b.length && [...a].sort().join(',') === [...b].sort().join(',');

/** Adds to the cart; the same product with the same contents is merged into its line. */
export function addLine(lines: CartLine[], productId: number, quantity: number, contents: number[] = []) {
    const existing = lines.find(line => line.product_id === productId && sameContents(line.contents, contents));
    if (existing) existing.quantity = Math.min(99, existing.quantity + quantity);
    else if (lines.length < MAX_LINES) lines.push({ product_id: productId, quantity, contents: [...contents] });
}

/** Price of one unit: the product plus what its chosen contents add (hampers); box contents add nothing. */
export function unitPrice(product: OrderProduct | undefined, contents: number[]): number {
    if (!product) return 0;
    return product.price + contents.reduce((sum, id) => sum + (product.contents.find(option => option.id === id)?.price ?? 0), 0);
}

/** "Berry (Fullsize)" or "2× Choco, 2× Keju" for the chosen contents of one unit. */
export function contentsLabel(product: OrderProduct | undefined, contents: number[]): string {
    if (!product || !contents.length) return '';
    const counts = new Map<number, number>();
    for (const id of contents) counts.set(id, (counts.get(id) ?? 0) + 1);
    return [...counts].map(([id, count]) => {
        const option = product.contents.find(item => item.id === id);
        const name = option ? `${option.name}${option.variant ? ` (${option.variant})` : ''}` : 'Isi tidak tersedia';
        return contents.length > 1 ? `${count}× ${name}` : name;
    }).join(', ');
}

/** Request body for the server: quoted_price is the unit price shown, so a price change is detected. */
export function toRequest(lines: CartLine[], products: Map<number, OrderProduct>) {
    return lines.map(line => ({ ...line, quoted_price: unitPrice(products.get(line.product_id), line.contents) }));
}
