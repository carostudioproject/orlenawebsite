import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

/**
 * Table filters that apply while typing. Text is debounced; selects and dates apply at once.
 * Empty values are dropped so the URL stays clean, and any change returns to page 1.
 */
export function useLiveFilters<T extends Record<string, string>>(url: () => string, initial: T, debounced: (keyof T)[] = ['search']) {
    const filters = reactive({ ...initial }) as T;
    const loading = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;

    function apply() {
        clearTimeout(timer);
        const query = Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, value.trim()]).filter(([, value]) => value !== ''));
        router.get(url(), query, {
            preserveState: true, preserveScroll: true, replace: true,
            onStart: () => { loading.value = true; }, onFinish: () => { loading.value = false; },
        });
    }

    for (const key of Object.keys(initial) as (keyof T)[]) {
        watch(() => filters[key], () => {
            clearTimeout(timer);
            if (debounced.includes(key)) timer = setTimeout(apply, 350);
            else apply();
        });
    }

    const active = computed(() => Object.values(filters).some(value => value.trim() !== ''));
    function reset() { for (const key of Object.keys(filters) as (keyof T)[]) filters[key] = '' as T[keyof T]; }
    onBeforeUnmount(() => clearTimeout(timer));

    return { filters, loading, active, apply, reset };
}
