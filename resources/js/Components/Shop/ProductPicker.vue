<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { rupiah } from '../../Support/money';
import { clampQuantity } from '../../Support/cart';
import type { ContentOption, OrderCategory, OrderProduct } from '../../Types/ordering';
// Category → short product card (one per size) → popup with contents, hampers and quantity → "Tambah ke keranjang".
// Shared by the new-order form and "Tambah pesanan".
const props = defineProps<{ categories: OrderCategory[]; products: OrderProduct[]; disabled?: boolean }>();
const emit = defineEmits<{ add: [productId: number, quantity: number, contents: number[]] }>();

// null = "Semua": every product, grouped in the category order; 'featured' = only the highlighted ones (e.g. an event).
// Highlighted products always come first; the server already sends them first, in their own order.
const activeCategory = ref<number | 'featured' | null>(null);
const featured = computed(() => props.products.filter(product => product.featured));
const visible = computed(() => {
    if (activeCategory.value === 'featured') return featured.value;
    if (activeCategory.value !== null) return props.products.filter(product => product.category_id === activeCategory.value);
    const order = new Map(props.categories.map((category, index) => [category.id, index]));
    return [...props.products].sort((a, b) => Number(!!b.featured) - Number(!!a.featured) || (a.featured ? 0 : (order.get(a.category_id) ?? 0) - (order.get(b.category_id) ?? 0)));
});
const counts = computed(() => {
    const totals = new Map<number, number>();
    for (const product of props.products) totals.set(product.category_id, (totals.get(product.category_id) ?? 0) + 1);
    return totals;
});
// Hampers holding one item, offered for the products they allow ("jadikan hampers").
const hampersFor = (product: OrderProduct) => (product.content_type ? [] : props.products.filter(hampers => hampers.content_type === 'hampers' && hampers.content_count === 1
    && hampers.contents.some(option => option.id === product.id)));
// Phones show one scrollable row; "Semua kategori" wraps them all into view. Wider screens always wrap.
const expanded = ref(false);
// The category bar sticks below the site header; its height is measured because it differs on phones and desktops.
const stickyTop = ref(0);
const categoryBar = ref<HTMLElement | null>(null);
const grid = ref<HTMLElement | null>(null);
function measure() { stickyTop.value = Math.round(document.querySelector('.header-navbar')?.getBoundingClientRect().height ?? 0); }
onMounted(() => { measure(); window.addEventListener('resize', measure); });
onBeforeUnmount(() => window.removeEventListener('resize', measure));
async function choose(id: number | 'featured' | null, event: MouseEvent) {
    activeCategory.value = id;
    expanded.value = false;
    (event.currentTarget as HTMLElement).scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    // Chosen further down the list: go back to the first product of the new category.
    await nextTick();
    const offset = stickyTop.value + (categoryBar.value?.offsetHeight ?? 0) + 8;
    const top = grid.value?.getBoundingClientRect().top ?? 0;
    if (top < offset) window.scrollTo({ top: window.scrollY + top - offset, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}

interface CardState { quantity: number; hampersId: number | null; single: number | null; picks: Record<number, number>; extras: Record<number, number> }
const cards = reactive<Record<number, CardState>>({});
const card = (product: OrderProduct) => (cards[product.id] ??= { quantity: 1, hampersId: null, single: null, picks: {}, extras: {} });
// Extras of the product's category (paper bag, candle, topper, card): added as their own cart lines.
const addonsFor = (product: OrderProduct) => {
    const ids = props.categories.find(category => category.id === product.category_id)?.addon_ids ?? [];
    return ids.map(id => props.products.find(item => item.id === id)).filter((item): item is OrderProduct => !!item && item.id !== product.id);
};
function extra(product: OrderProduct, addon: OrderProduct, step: number) {
    const state = card(product);
    state.extras[addon.id] = Math.min(99, Math.max(0, (state.extras[addon.id] ?? 0) + step));
}
const extrasTotal = (product: OrderProduct) => addonsFor(product).reduce((sum, addon) => sum + addon.price * (card(product).extras[addon.id] ?? 0), 0);
const picked = (product: OrderProduct) => Object.values(card(product).picks).reduce((sum, count) => sum + count, 0);
/** Contents chosen for one unit, or null while incomplete. */
function chosen(product: OrderProduct): number[] | null {
    if (!isChoice(product)) return [];
    if (product.content_count === 1) return card(product).single ? [card(product).single as number] : null;
    if (picked(product) !== product.content_count) return null;
    return Object.entries(card(product).picks).flatMap(([id, count]) => Array<number>(count).fill(Number(id)));
}
function pick(product: OrderProduct, option: ContentOption, step: number) {
    const state = card(product);
    const next = (state.picks[option.id] ?? 0) + step;
    if (next < 0 || (step > 0 && picked(product) >= (product.content_count ?? 0))) return;
    state.picks[option.id] = next;
}
/** Hampers and Mini Size Mix boxes ask for contents; a box with fixed flavours does not. */
const isChoice = (product: OrderProduct) => product.content_type === 'hampers' || product.content_type === 'box';
const chosenHampers = (product: OrderProduct) => props.products.find(hampers => hampers.id === card(product).hampersId) ?? null;
/** Price of one unit, including the chosen contents or hampers. */
function unitPrice(product: OrderProduct): number {
    const hampers = chosenHampers(product);
    if (hampers) return hampers.price + (hampers.contents.find(option => option.id === product.id)?.price ?? 0);
    const contents = chosen(product) ?? [];
    return product.price + contents.reduce((sum, id) => sum + (product.contents.find(option => option.id === id)?.price ?? 0), 0);
}
const optionLabel = (option: ContentOption) => `${option.name}${option.variant ? ` (${option.variant})` : ''}`;
const formatDate = (date: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));

// Cards stay short; quantity, contents and "jadikan hampers" are chosen in a popup (a bottom sheet on phones).
const dialog = ref<HTMLDialogElement | null>(null);
const selected = ref<OrderProduct | null>(null);
function open(product: OrderProduct) {
    Object.assign(card(product), { quantity: 1, hampersId: null, single: null, picks: {}, extras: {} });
    selected.value = product;
    dialog.value?.showModal();
    document.documentElement.classList.add('overflow-hidden');
}
function close() { dialog.value?.close(); }
function onClose() { document.documentElement.classList.remove('overflow-hidden'); }
// A tap on the dimmed backdrop closes the popup.
function backdrop(event: MouseEvent) { if (event.target === dialog.value) close(); }
const ready = (product: OrderProduct) => !!chosenHampers(product) || !!chosen(product);
function addLabel(product: OrderProduct): string {
    if (ready(product)) return 'Tambah ke keranjang';
    return product.content_type === 'box' ? `Pilih ${(product.content_count ?? 0) - picked(product)} isi lagi` : 'Pilih isi dulu';
}

const justAdded = ref<number | null>(null);
function add(product: OrderProduct) {
    const hampers = chosenHampers(product);
    const contents = hampers ? [product.id] : chosen(product);
    if (!contents) return;
    emit('add', hampers ? hampers.id : product.id, clampQuantity(card(product).quantity), contents);
    for (const addon of addonsFor(product)) {
        const quantity = card(product).extras[addon.id] ?? 0;
        if (quantity > 0) emit('add', addon.id, quantity, []);
    }
    close();
    justAdded.value = product.id;
    setTimeout(() => { if (justAdded.value === product.id) justAdded.value = null; }, 1600);
}
</script>
<template>
    <div class="space-y-5">
        <!-- Stays under the sticky site header while scrolling, so the category can be changed from anywhere in the list. -->
        <div ref="categoryBar" class="order-category-bar space-y-2" :style="{ top: `${stickyTop}px` }">
            <div class="flex items-center justify-between gap-3 sm:hidden">
                <p class="m-0 text-sm font-bold">Kategori</p>
                <button v-if="categories.length > 3" type="button" class="text-sm font-bold underline" :aria-expanded="expanded" aria-controls="order-categories" @click="expanded = !expanded">{{ expanded ? 'Ringkas' : `Tampilkan semua (${categories.length + 1})` }}</button>
            </div>
            <div class="relative">
                <div id="order-categories" role="group" aria-label="Kategori" class="order-categories -mx-1 flex gap-2 px-1 pb-1 sm:flex-wrap sm:overflow-visible" :class="expanded ? 'flex-wrap' : 'overflow-x-auto'">
                    <button type="button" class="order-category" :aria-pressed="activeCategory === null" @click="choose(null, $event)"><span class="hidden size-9 shrink-0 items-center justify-center rounded-full bg-cream sm:flex"><i class="fa-solid fa-table-cells-large text-xs" aria-hidden="true"></i></span>Semua<span class="order-category-count">{{ products.length }}</span></button>
                    <button v-if="featured.length" type="button" class="order-category is-featured" :aria-pressed="activeCategory === 'featured'" @click="choose('featured', $event)"><span class="hidden size-9 shrink-0 items-center justify-center rounded-full bg-almond sm:flex"><i class="fa-solid fa-star text-xs" aria-hidden="true"></i></span><i class="fa-solid fa-star text-xs sm:hidden" aria-hidden="true"></i>Sorotan<span class="order-category-count">{{ featured.length }}</span></button>
                    <button v-for="category in categories" :key="category.id" type="button" class="order-category" :aria-pressed="activeCategory === category.id" @click="choose(category.id, $event)">
                        <span class="hidden size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-cream sm:flex"><img v-if="category.image" :src="category.image" alt="" class="size-full object-cover"><i v-else class="fa-solid fa-cookie-bite text-xs" aria-hidden="true"></i></span>{{ category.name }}<span class="order-category-count">{{ counts.get(category.id) ?? 0 }}</span>
                    </button>
                </div>
                <!-- Hint that the row scrolls on phones. -->
                <span v-if="!expanded" class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-linear-to-l from-white to-transparent sm:hidden" aria-hidden="true"></span>
            </div>
        </div>

        <div ref="grid" class="grid grid-cols-2 gap-x-4 gap-y-6 lg:grid-cols-3">
            <article v-for="product in visible" :key="product.id" class="order-product">
                <!-- Photo fills the card, like a shop grid; the whole card opens the popup. -->
                <button type="button" class="order-product-photo" tabindex="-1" aria-hidden="true" @click="open(product)">
                    <img v-if="product.image" :src="product.image" alt="" loading="lazy"><i v-else class="fa-solid fa-cookie-bite text-3xl text-chocolate/25"></i>
                    <span v-if="product.featured" class="order-product-featured"><i class="fa-solid fa-star" aria-hidden="true"></i> {{ product.featured_label || 'Pilihan' }}</span>
                    <span v-if="product.content_type" class="order-product-badge"><i class="fa-solid" :class="product.content_type === 'hampers' ? 'fa-gift' : 'fa-box-open'"></i> {{ product.content_type === 'hampers' ? 'Pilih isi' : product.content_type === 'set' ? 'Isi tetap' : `Pilih ${product.content_count} isi` }}</span>
                </button>
                <div class="flex flex-1 flex-col items-center gap-0.5 px-1 pt-3 text-center">
                    <p v-if="product.variant" class="m-0 text-[11px] font-bold uppercase tracking-wider text-matcha">{{ product.variant }}</p>
                    <h3 class="m-0 text-base font-bold leading-snug sm:text-lg">{{ product.name }}</h3>
                    <p class="m-0 mt-auto pt-1 text-sm font-bold tabular-nums">{{ rupiah(product.price) }}<span v-if="product.content_type === 'hampers'" class="font-normal text-chocolate/65"> + isi</span></p>
                </div>
                <button type="button" class="mt-3 w-full" :class="justAdded === product.id ? 'admin-primary' : 'admin-secondary'" :disabled="disabled" :aria-label="`${isChoice(product) ? 'Pilih' : 'Tambah'} ${product.name}${product.variant ? ` ${product.variant}` : ''}`" @click="open(product)"><i class="fa-solid" :class="justAdded === product.id ? 'fa-check' : isChoice(product) ? 'fa-list-check' : 'fa-cart-plus'" aria-hidden="true"></i>{{ justAdded === product.id ? 'Ditambahkan' : isChoice(product) ? 'Pilih' : 'Tambah' }}</button>
            </article>
        </div>
        <p v-if="!visible.length" class="admin-muted text-sm">Belum ada produk di kategori ini.</p>

        <!-- Popup photos load at once: lazy images inside a dialog may never load until scrolled. -->
        <dialog ref="dialog" class="order-sheet" aria-labelledby="order-sheet-title" @close="onClose" @click="backdrop">
            <template v-if="selected">
                <div class="order-sheet-body">
                    <div class="flex items-start gap-3">
                        <img v-if="selected.image" :src="selected.image" alt="" class="size-20 shrink-0 rounded-xl object-cover">
                        <div class="min-w-0 flex-1">
                            <h2 id="order-sheet-title" class="m-0 text-lg leading-snug">{{ selected.name }}</h2>
                            <p v-if="selected.variant" class="m-0 text-xs font-bold text-matcha">{{ selected.variant }}</p>
                            <p class="m-0 mt-1 text-sm font-bold tabular-nums">{{ rupiah(selected.price) }}<span v-if="selected.content_type === 'hampers'" class="font-normal text-chocolate/65"> + harga isi</span></p>
                        </div>
                        <button type="button" class="admin-action-icon" aria-label="Tutup" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <p v-if="selected.description" class="admin-muted mt-3 text-sm">{{ selected.description }}</p>
                    <div v-if="selected.fixed_contents.length" class="mt-4">
                        <p class="m-0 mb-2 text-sm font-bold"><i class="fa-solid fa-box" aria-hidden="true"></i> Isi box</p>
                        <ul class="m-0 grid list-none grid-cols-1 gap-1.5 p-0">
                            <li v-for="item in selected.fixed_contents" :key="item.id" class="order-content-option">
                                <span class="order-thumb"><img v-if="item.image" :src="item.image" alt=""><i v-else class="fa-solid fa-cookie-bite" aria-hidden="true"></i></span>
                                <span class="flex-1">{{ item.name }}{{ item.variant ? ` (${item.variant})` : '' }}</span><span class="font-bold tabular-nums">{{ item.quantity }}×</span>
                            </li>
                        </ul>
                    </div>
                    <div v-if="selected.hamper_items.length" class="mt-3 rounded-xl bg-cream/70 p-3 text-xs"><p class="m-0 mb-1 font-bold"><i class="fa-solid fa-gift" aria-hidden="true"></i> Isi hampers</p><ul class="m-0 list-disc space-y-0.5 pl-4"><li v-for="item in selected.hamper_items" :key="item">{{ item }}</li></ul></div>
                    <p v-if="selected.sale_ends_on" class="admin-muted mt-2 text-xs"><i class="fa-regular fa-clock" aria-hidden="true"></i> Tersedia sampai {{ formatDate(selected.sale_ends_on) }}</p>

                    <!-- Hampers: choose the item inside, as picture tiles. -->
                    <fieldset v-if="isChoice(selected) && selected.content_count === 1" class="mt-4">
                        <legend class="mb-2 text-sm font-bold">{{ selected.content_type === 'hampers' ? 'Pilih isi hampers' : 'Pilih isi' }}</legend>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <label v-for="option in selected.contents" :key="option.id" class="order-tile">
                                <input v-model="card(selected).single" type="radio" :name="`content-${selected.id}`" :value="option.id" class="sr-only">
                                <span class="order-tile-photo"><img v-if="option.image" :src="option.image" alt=""><i v-else class="fa-solid fa-cookie-bite" aria-hidden="true"></i></span>
                                <span class="block px-1 pt-1.5 text-xs font-bold leading-snug">{{ optionLabel(option) }}</span>
                                <span v-if="option.price" class="block px-1 text-xs tabular-nums text-chocolate/70">+{{ rupiah(option.price) }}</span>
                            </label>
                        </div>
                    </fieldset>
                    <!-- Box: choose N, repeats allowed. -->
                    <fieldset v-else-if="isChoice(selected)" class="mt-4 space-y-1.5">
                        <legend class="mb-1 flex w-full justify-between text-sm font-bold"><span>Pilih {{ selected.content_count }} isi (boleh sama)</span><span class="tabular-nums" :class="{ 'text-matcha': picked(selected) === selected.content_count }" aria-live="polite">{{ picked(selected) }}/{{ selected.content_count }}</span></legend>
                        <div v-for="option in selected.contents" :key="option.id" class="order-content-option">
                            <span class="order-thumb"><img v-if="option.image" :src="option.image" alt=""><i v-else class="fa-solid fa-cookie-bite" aria-hidden="true"></i></span>
                            <span class="flex-1">{{ optionLabel(option) }}<span v-if="option.price" class="text-chocolate/70"> · +{{ rupiah(option.price) }}</span></span>
                            <div class="order-stepper is-small" role="group" :aria-label="`Jumlah ${optionLabel(option)}`">
                                <button type="button" :aria-label="`Kurangi ${optionLabel(option)}`" :disabled="!card(selected).picks[option.id]" @click="pick(selected, option, -1)"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>
                                <span class="order-qty" aria-live="polite">{{ card(selected).picks[option.id] ?? 0 }}</span>
                                <button type="button" :aria-label="`Tambah ${optionLabel(option)}`" :disabled="picked(selected) >= (selected.content_count ?? 0)" @click="pick(selected, option, 1)"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </fieldset>
                    <!-- Regular product: optionally packed in a hampers that allows it, as picture tiles. -->
                    <fieldset v-else-if="hampersFor(selected).length" class="mt-4">
                        <legend class="mb-2 text-sm font-bold"><i class="fa-solid fa-gift" aria-hidden="true"></i> Jadikan hampers? (opsional)</legend>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <label class="order-tile">
                                <input v-model="card(selected).hampersId" type="radio" :name="`hampers-${selected.id}`" :value="null" class="sr-only">
                                <span class="order-tile-photo"><i class="fa-solid fa-ban" aria-hidden="true"></i></span>
                                <span class="block px-1 pt-1.5 text-xs font-bold">Tanpa hampers</span>
                            </label>
                            <label v-for="hampers in hampersFor(selected)" :key="hampers.id" class="order-tile">
                                <input v-model="card(selected).hampersId" type="radio" :name="`hampers-${selected.id}`" :value="hampers.id" class="sr-only">
                                <span class="order-tile-photo"><img v-if="hampers.image" :src="hampers.image" alt=""><i v-else class="fa-solid fa-gift" aria-hidden="true"></i></span>
                                <span class="block px-1 pt-1.5 text-xs font-bold leading-snug">{{ hampers.name }}{{ hampers.variant ? ` (${hampers.variant})` : '' }}</span>
                                <span class="block px-1 text-xs tabular-nums text-chocolate/70">+{{ rupiah(hampers.price) }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="mt-4 flex items-center justify-between gap-3">
                        <span class="text-sm font-bold">Jumlah</span>
                        <div class="order-stepper" role="group" :aria-label="`Jumlah ${selected.name}`"><button type="button" aria-label="Kurangi" :disabled="card(selected).quantity <= 1" @click="card(selected).quantity--"><i class="fa-solid fa-minus" aria-hidden="true"></i></button><input v-model.number="card(selected).quantity" class="order-qty" type="number" min="1" max="99" inputmode="numeric" :aria-label="`Jumlah ${selected.name}`" @change="card(selected).quantity = clampQuantity(card(selected).quantity)"><button type="button" aria-label="Tambah" :disabled="card(selected).quantity >= 99" @click="card(selected).quantity++"><i class="fa-solid fa-plus" aria-hidden="true"></i></button></div>
                    </div>
                    <p v-if="(isChoice(selected) || chosenHampers(selected)) && card(selected).quantity > 1" class="admin-muted m-0 mt-2 text-xs">Isi yang sama untuk {{ card(selected).quantity }} {{ selected.content_type === 'box' ? 'box' : 'hampers' }}. Untuk isi berbeda, tambahkan satu per satu.</p>

                    <fieldset v-if="addonsFor(selected).length" class="mt-5 space-y-1.5">
                        <legend class="mb-1 text-sm font-bold">Tambahan <span class="font-normal text-chocolate/65">(opsional)</span></legend>
                        <div v-for="addon in addonsFor(selected)" :key="addon.id" class="order-content-option">
                            <span class="order-thumb"><img v-if="addon.image" :src="addon.image" alt=""><i v-else class="fa-solid fa-gift" aria-hidden="true"></i></span>
                            <span class="flex-1">{{ addon.name }}{{ addon.variant ? ` (${addon.variant})` : '' }}<span class="text-chocolate/70"> · +{{ rupiah(addon.price) }}</span></span>
                            <div class="order-stepper is-small" role="group" :aria-label="`Jumlah ${addon.name}`">
                                <button type="button" :aria-label="`Kurangi ${addon.name}`" :disabled="!card(selected).extras[addon.id]" @click="extra(selected, addon, -1)"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>
                                <span class="order-qty" aria-live="polite">{{ card(selected).extras[addon.id] ?? 0 }}</span>
                                <button type="button" :aria-label="`Tambah ${addon.name}`" :disabled="(card(selected).extras[addon.id] ?? 0) >= 99" @click="extra(selected, addon, 1)"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </fieldset>
                </div>
                <div class="order-sheet-footer">
                    <div class="min-w-0">
                        <p class="m-0 text-xs text-chocolate/65">Total</p>
                        <p class="m-0 text-lg font-bold tabular-nums">{{ ready(selected) ? rupiah(unitPrice(selected) * clampQuantity(card(selected).quantity) + extrasTotal(selected)) : '—' }}</p>
                    </div>
                    <button type="button" class="admin-primary flex-1" :disabled="disabled || !ready(selected)" @click="add(selected)"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i>{{ addLabel(selected) }}</button>
                </div>
            </template>
        </dialog>
    </div>
</template>
