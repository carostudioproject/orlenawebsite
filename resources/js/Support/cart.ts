import type { OrderProduct } from '../Types/ordering';

/**
 * One cart line: a product, how many, for hampers/box the contents chosen for one unit (product IDs, repeats allowed),
 * and the customer's texts when the product asks for one (greeting card, writing on a cake…): one per unit, so 3 cards carry
 * 3 greetings. Texts beyond the quantity are kept while editing (lowering then raising it) and not sent.
 */
export interface CartLine { product_id: number; quantity: number; contents: number[]; input_texts: string[] }

export const MAX_LINES = 50;
export const clampQuantity = (value: number) => Math.min(99, Math.max(1, Math.round(value) || 1));

const sameContents = (a: number[], b: number[]) => a.length === b.length && [...a].sort().join(',') === [...b].sort().join(',');

/** Adds to the cart; the same product with the same contents is merged into its line (a field per added unit appears). */
export function addLine(lines: CartLine[], productId: number, quantity: number, contents: number[] = []) {
    const existing = lines.find(line => line.product_id === productId && sameContents(line.contents, contents));
    if (existing) existing.quantity = Math.min(99, existing.quantity + quantity);
    else if (lines.length < MAX_LINES) lines.push({ product_id: productId, quantity, contents: [...contents], input_texts: [] });
}

/** Sets the text of one unit (0-based) of a line. */
export function setInputText(line: CartLine, unit: number, text: string) {
    const texts = [...line.input_texts];
    while (texts.length < unit) texts.push('');
    texts[unit] = text;
    line.input_texts = texts;
}

/** Price of one unit: the product plus what its chosen contents add (hampers); box contents add nothing. */
export function unitPrice(product: OrderProduct | undefined, contents: number[]): number {
    if (!product) return 0;
    return product.price + contents.reduce((sum, id) => sum + (product.contents.find(option => option.id === id)?.price ?? 0), 0);
}

/** "Berry (Fullsize)" or "2× Choco, 2× Keju" for the chosen contents of one unit. */
export function contentsLabel(product: OrderProduct | undefined, contents: number[]): string {
    if (!product) return '';
    // A box with fixed flavours shows what it holds.
    if (!contents.length) return product.fixed_contents.map(item => `${item.quantity}× ${item.name}`).join(', ');
    const counts = new Map<number, number>();
    for (const id of contents) counts.set(id, (counts.get(id) ?? 0) + 1);
    return [...counts].map(([id, count]) => {
        const option = product.contents.find(item => item.id === id);
        const name = option ? `${option.name}${option.variant ? ` (${option.variant})` : ''}` : 'Isi tidak tersedia';
        return contents.length > 1 ? `${count}× ${name}` : name;
    }).join(', ');
}

/** Required texts still empty on a line, as error keys (items.N.input_texts.U, like the server's) with their messages. */
export function missingInputs(line: CartLine, index: number, product: OrderProduct | undefined): [string, string][] {
    const input = product?.input;
    if (!input?.required) return [];
    return Array.from({ length: line.quantity }, (_, unit) => unit)
        .filter(unit => !(line.input_texts[unit] ?? '').trim())
        .map(unit => [`items.${index}.input_texts.${unit}`, `${input.label}${line.quantity > 1 ? ` ${unit + 1}` : ''} wajib diisi.`]);
}

/** Request body for the server: quoted_price is the unit price shown, so a price change is detected. */
export function toRequest(lines: CartLine[], products: Map<number, OrderProduct>) {
    return lines.map(line => ({
        ...line, quoted_price: unitPrice(products.get(line.product_id), line.contents),
        input_texts: products.get(line.product_id)?.input ? line.input_texts.slice(0, line.quantity) : [],
    }));
}
