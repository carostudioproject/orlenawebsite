<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { rupiah } from '../../Support/money';
import type { OrderCategory, OrderProduct } from '../../Types/ordering';
// Category → product → variant → quantity → "Tambah". Shared by the new-order form and "Tambah pesanan".
const props = defineProps<{ categories: OrderCategory[]; products: OrderProduct[]; disabled?: boolean }>();
const emit = defineEmits<{ add: [productId: number, quantity: number] }>();

const activeCategory = ref<number | null>(props.categories[0]?.id ?? null);
// Products with the same name in a category are one item with variant choices (e.g. Fullsize/Halfsize); the server lists them by price, highest first.
const groups = computed(() => {
    const byName = new Map<string, OrderProduct[]>();
    for (const product of props.products.filter(product => product.category_id === activeCategory.value)) {
        byName.set(product.name, [...(byName.get(product.name) ?? []), product]);
    }
    return [...byName.entries()].map(([name, variants]) => ({
        key: `${activeCategory.value}:${name}`, name, variants,
        description: variants.find(variant => variant.description)?.description ?? null, image: variants.find(variant => variant.image)?.image ?? null,
        hamperItems: variants.find(variant => variant.is_hamper)?.hamper_items ?? [], saleEndsOn: variants.find(variant => variant.sale_ends_on)?.sale_ends_on ?? null,
    }));
});
const pickers = reactive<Record<string, { productId: number; quantity: number }>>({});
const picker = (group: { key: string; variants: OrderProduct[] }) => (pickers[group.key] ??= { productId: group.variants[0].id, quantity: 1 });
const formatDate = (date: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
const clamp = (value: number) => Math.min(99, Math.max(1, Math.round(value) || 1));
const justAdded = ref<string | null>(null);
function add(group: { key: string; variants: OrderProduct[] }) {
    const { productId, quantity } = picker(group);
    emit('add', productId, clamp(quantity));
    pickers[group.key].quantity = 1;
    justAdded.value = group.key;
    setTimeout(() => { if (justAdded.value === group.key) justAdded.value = null; }, 1600);
}
</script>
<template>
    <div class="space-y-5">
        <div role="group" aria-label="Kategori" class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            <button v-for="category in categories" :key="category.id" type="button" class="order-category" :aria-pressed="activeCategory === category.id" @click="activeCategory = category.id">
                <span class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-cream"><img v-if="category.image" :src="category.image" alt="" class="size-full object-cover"><i v-else class="fa-solid fa-cookie-bite text-xs" aria-hidden="true"></i></span>{{ category.name }}
            </button>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <article v-for="group in groups" :key="group.key" class="order-product">
                <div v-if="group.image" class="aspect-4/3 overflow-hidden rounded-xl bg-cream"><img :src="group.image" alt="" class="size-full object-cover" loading="lazy"></div>
                <div class="flex flex-1 flex-col gap-3">
                    <div><h3 class="m-0 text-base font-bold">{{ group.name }}</h3><p v-if="group.description" class="admin-muted mt-1 line-clamp-2 text-xs">{{ group.description }}</p></div>
                    <div v-if="group.hamperItems.length" class="rounded-xl bg-cream/70 p-3 text-xs"><p class="m-0 mb-1 font-bold"><i class="fa-solid fa-gift" aria-hidden="true"></i> Isi hampers</p><ul class="m-0 list-disc space-y-0.5 pl-4"><li v-for="item in group.hamperItems" :key="item">{{ item }}</li></ul></div>
                    <p v-if="group.saleEndsOn" class="admin-muted m-0 text-xs"><i class="fa-regular fa-clock" aria-hidden="true"></i> Tersedia sampai {{ formatDate(group.saleEndsOn) }}</p>
                    <fieldset v-if="group.variants.length > 1" class="flex flex-wrap gap-2"><legend class="sr-only">Varian {{ group.name }}</legend>
                        <label v-for="variant in group.variants" :key="variant.id" class="order-size"><input v-model="picker(group).productId" type="radio" :name="`size-${group.key}`" :value="variant.id" class="sr-only"><span>{{ variant.variant ?? 'Standar' }} · {{ rupiah(variant.price) }}</span></label>
                    </fieldset>
                    <p v-else class="m-0 font-bold">{{ rupiah(group.variants[0].price) }}<span v-if="group.variants[0].variant" class="admin-muted text-xs font-normal"> · {{ group.variants[0].variant }}</span></p>
                    <div class="mt-auto flex flex-wrap items-center gap-2">
                        <div class="order-stepper" role="group" :aria-label="`Jumlah ${group.name}`"><button type="button" aria-label="Kurangi" :disabled="picker(group).quantity <= 1" @click="picker(group).quantity--"><i class="fa-solid fa-minus" aria-hidden="true"></i></button><input v-model.number="picker(group).quantity" class="order-qty" type="number" min="1" max="99" inputmode="numeric" :aria-label="`Jumlah ${group.name}`" @change="picker(group).quantity = clamp(picker(group).quantity)"><button type="button" aria-label="Tambah" :disabled="picker(group).quantity >= 99" @click="picker(group).quantity++"><i class="fa-solid fa-plus" aria-hidden="true"></i></button></div>
                        <button type="button" class="admin-primary flex-1" :disabled="disabled" @click="add(group)"><i class="fa-solid" :class="justAdded === group.key ? 'fa-check' : 'fa-cart-plus'" aria-hidden="true"></i>{{ justAdded === group.key ? 'Ditambahkan' : 'Tambah' }}</button>
                    </div>
                </div>
            </article>
        </div>
        <p v-if="!groups.length" class="admin-muted text-sm">Belum ada produk di kategori ini.</p>
    </div>
</template>
