<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { rupiah } from '../../Support/money';
import { clampQuantity } from '../../Support/cart';
import type { ContentOption, OrderCategory, OrderProduct } from '../../Types/ordering';
// Category → product card (one per size) → contents for hampers/box → quantity → "Tambah". Shared by the new-order form and "Tambah pesanan".
const props = defineProps<{ categories: OrderCategory[]; products: OrderProduct[]; disabled?: boolean }>();
const emit = defineEmits<{ add: [productId: number, quantity: number, contents: number[]] }>();

const activeCategory = ref<number | null>(props.categories[0]?.id ?? null);
const visible = computed(() => props.products.filter(product => product.category_id === activeCategory.value));
const counts = computed(() => {
    const totals = new Map<number, number>();
    for (const product of props.products) totals.set(product.category_id, (totals.get(product.category_id) ?? 0) + 1);
    return totals;
});
// Hampers holding one item, offered on the cards of the products they allow ("jadikan hampers").
const hampersFor = (product: OrderProduct) => (product.content_type ? [] : props.products.filter(hampers => hampers.content_type === 'hampers' && hampers.content_count === 1
    && hampers.contents.some(option => option.id === product.id)));
// Phones show one scrollable row; "Semua kategori" wraps them all into view. Wider screens always wrap.
const expanded = ref(false);
function choose(id: number, event: MouseEvent) {
    activeCategory.value = id;
    expanded.value = false;
    (event.currentTarget as HTMLElement).scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
}

interface CardState { quantity: number; hampersId: number | null; single: number | null; picks: Record<number, number> }
const cards = reactive<Record<number, CardState>>({});
const card = (product: OrderProduct) => (cards[product.id] ??= { quantity: 1, hampersId: null, single: null, picks: {} });
const picked = (product: OrderProduct) => Object.values(card(product).picks).reduce((sum, count) => sum + count, 0);
/** Contents chosen for one unit, or null while incomplete. */
function chosen(product: OrderProduct): number[] | null {
    if (!product.content_type) return [];
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
const chosenHampers = (product: OrderProduct) => props.products.find(hampers => hampers.id === card(product).hampersId) ?? null;
/** Unit price shown on the card, including the chosen contents or hampers. */
function cardPrice(product: OrderProduct): number {
    const hampers = chosenHampers(product);
    if (hampers) return hampers.price + (hampers.contents.find(option => option.id === product.id)?.price ?? 0);
    const contents = chosen(product) ?? [];
    return product.price + contents.reduce((sum, id) => sum + (product.contents.find(option => option.id === id)?.price ?? 0), 0);
}
const optionLabel = (option: ContentOption) => `${option.name}${option.variant ? ` (${option.variant})` : ''}`;

const formatDate = (date: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
const justAdded = ref<number | null>(null);
function add(product: OrderProduct) {
    const state = card(product);
    const hampers = chosenHampers(product);
    const contents = hampers ? [product.id] : chosen(product);
    if (!contents) return;
    emit('add', hampers ? hampers.id : product.id, clampQuantity(state.quantity), contents);
    Object.assign(state, { quantity: 1, hampersId: null, single: null, picks: {} });
    justAdded.value = product.id;
    setTimeout(() => { if (justAdded.value === product.id) justAdded.value = null; }, 1600);
}
</script>
<template>
    <div class="space-y-5">
        <div class="space-y-2">
            <div class="flex items-center justify-between gap-3 sm:hidden">
                <p class="m-0 text-sm font-bold">Kategori</p>
                <button v-if="categories.length > 3" type="button" class="text-sm font-bold underline" :aria-expanded="expanded" aria-controls="order-categories" @click="expanded = !expanded">{{ expanded ? 'Ringkas' : `Semua kategori (${categories.length})` }}</button>
            </div>
            <div class="relative">
                <div id="order-categories" role="group" aria-label="Kategori" class="order-categories -mx-1 flex gap-2 px-1 pb-1 sm:flex-wrap sm:overflow-visible" :class="expanded ? 'flex-wrap' : 'overflow-x-auto'">
                    <button v-for="category in categories" :key="category.id" type="button" class="order-category" :aria-pressed="activeCategory === category.id" @click="choose(category.id, $event)">
                        <span class="hidden size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-cream sm:flex"><img v-if="category.image" :src="category.image" alt="" class="size-full object-cover"><i v-else class="fa-solid fa-cookie-bite text-xs" aria-hidden="true"></i></span>{{ category.name }}<span class="order-category-count">{{ counts.get(category.id) ?? 0 }}</span>
                    </button>
                </div>
                <!-- Hint that the row scrolls on phones. -->
                <span v-if="!expanded" class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-linear-to-l from-white to-transparent sm:hidden" aria-hidden="true"></span>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <article v-for="product in visible" :key="product.id" class="order-product">
                <div v-if="product.image" class="aspect-4/3 overflow-hidden rounded-xl bg-cream"><img :src="product.image" alt="" class="size-full object-cover" loading="lazy"></div>
                <div class="flex flex-1 flex-col gap-3">
                    <div>
                        <h3 class="m-0 text-base font-bold">{{ product.name }}</h3>
                        <p v-if="product.variant" class="m-0 text-xs font-bold text-matcha">{{ product.variant }}</p>
                        <p v-if="product.description" class="admin-muted mt-1 line-clamp-2 text-xs">{{ product.description }}</p>
                    </div>
                    <div v-if="product.hamper_items.length" class="rounded-xl bg-cream/70 p-3 text-xs"><p class="m-0 mb-1 font-bold"><i class="fa-solid fa-gift" aria-hidden="true"></i> Isi hampers</p><ul class="m-0 list-disc space-y-0.5 pl-4"><li v-for="item in product.hamper_items" :key="item">{{ item }}</li></ul></div>
                    <p v-if="product.sale_ends_on" class="admin-muted m-0 text-xs"><i class="fa-regular fa-clock" aria-hidden="true"></i> Tersedia sampai {{ formatDate(product.sale_ends_on) }}</p>

                    <!-- Hampers: choose the item inside. -->
                    <fieldset v-if="product.content_type && product.content_count === 1" class="space-y-1.5">
                        <legend class="mb-1 text-xs font-bold">{{ product.content_type === 'hampers' ? 'Pilih isi hampers' : 'Pilih isi' }}</legend>
                        <label v-for="option in product.contents" :key="option.id" class="order-content-option">
                            <input v-model="card(product).single" type="radio" :name="`content-${product.id}`" :value="option.id">
                            <span class="flex-1">{{ optionLabel(option) }}</span><span v-if="option.price" class="tabular-nums text-chocolate/70">+{{ rupiah(option.price) }}</span>
                        </label>
                    </fieldset>
                    <!-- Box: choose N, repeats allowed. -->
                    <fieldset v-else-if="product.content_type" class="space-y-1.5">
                        <legend class="mb-1 flex w-full justify-between text-xs font-bold"><span>Pilih {{ product.content_count }} isi (boleh sama)</span><span :class="picked(product) === product.content_count ? 'text-matcha' : ''" aria-live="polite">{{ picked(product) }}/{{ product.content_count }}</span></legend>
                        <div v-for="option in product.contents" :key="option.id" class="order-content-option">
                            <span class="flex-1">{{ optionLabel(option) }}<span v-if="option.price" class="text-chocolate/70"> · +{{ rupiah(option.price) }}</span></span>
                            <div class="order-stepper is-small" role="group" :aria-label="`Jumlah ${optionLabel(option)}`">
                                <button type="button" :aria-label="`Kurangi ${optionLabel(option)}`" :disabled="!card(product).picks[option.id]" @click="pick(product, option, -1)"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>
                                <span class="order-qty" aria-live="polite">{{ card(product).picks[option.id] ?? 0 }}</span>
                                <button type="button" :aria-label="`Tambah ${optionLabel(option)}`" :disabled="picked(product) >= (product.content_count ?? 0)" @click="pick(product, option, 1)"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </fieldset>
                    <!-- Regular product: optionally packed in a hampers that allows it. -->
                    <fieldset v-else-if="hampersFor(product).length" class="space-y-1.5 rounded-xl bg-cream/60 p-3">
                        <legend class="sr-only">Jadikan hampers</legend>
                        <p class="m-0 text-xs font-bold"><i class="fa-solid fa-gift" aria-hidden="true"></i> Jadikan hampers? (opsional)</p>
                        <label class="order-content-option"><input v-model="card(product).hampersId" type="radio" :name="`hampers-${product.id}`" :value="null"><span class="flex-1">Tanpa hampers</span></label>
                        <label v-for="hampers in hampersFor(product)" :key="hampers.id" class="order-content-option">
                            <input v-model="card(product).hampersId" type="radio" :name="`hampers-${product.id}`" :value="hampers.id">
                            <span class="flex-1">{{ hampers.name }}{{ hampers.variant ? ` (${hampers.variant})` : '' }}</span><span class="tabular-nums text-chocolate/70">+{{ rupiah(hampers.price) }}</span>
                        </label>
                    </fieldset>

                    <p class="m-0 font-bold tabular-nums">
                        <template v-if="product.content_type === 'hampers' && !chosen(product)">{{ rupiah(product.price) }} <span class="text-xs font-normal text-chocolate/65">+ harga isi</span></template>
                        <template v-else>{{ rupiah(cardPrice(product)) }}<span v-if="chosenHampers(product)" class="text-xs font-normal text-chocolate/65"> · termasuk hampers</span></template>
                    </p>
                    <div class="mt-auto flex flex-wrap items-center gap-2">
                        <div class="order-stepper" role="group" :aria-label="`Jumlah ${product.name}`"><button type="button" aria-label="Kurangi" :disabled="card(product).quantity <= 1" @click="card(product).quantity--"><i class="fa-solid fa-minus" aria-hidden="true"></i></button><input v-model.number="card(product).quantity" class="order-qty" type="number" min="1" max="99" inputmode="numeric" :aria-label="`Jumlah ${product.name}`" @change="card(product).quantity = clampQuantity(card(product).quantity)"><button type="button" aria-label="Tambah" :disabled="card(product).quantity >= 99" @click="card(product).quantity++"><i class="fa-solid fa-plus" aria-hidden="true"></i></button></div>
                        <button type="button" class="admin-primary flex-1" :disabled="disabled || (!chosenHampers(product) && !chosen(product))" @click="add(product)"><i class="fa-solid" :class="justAdded === product.id ? 'fa-check' : 'fa-cart-plus'" aria-hidden="true"></i>{{ justAdded === product.id ? 'Ditambahkan' : 'Tambah' }}</button>
                    </div>
                    <p v-if="product.content_type && card(product).quantity > 1" class="admin-muted m-0 text-xs">Isi yang sama untuk {{ card(product).quantity }} {{ product.content_type === 'hampers' ? 'hampers' : 'box' }}. Untuk isi berbeda, tambahkan satu per satu.</p>
                </div>
            </article>
        </div>
        <p v-if="!visible.length" class="admin-muted text-sm">Belum ada produk di kategori ini.</p>
    </div>
</template>
