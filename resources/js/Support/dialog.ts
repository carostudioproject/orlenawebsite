import { reactive } from 'vue';

export interface DialogOptions {
    title: string;
    message?: string;
    confirmLabel?: string;
    cancelLabel?: string | null;
    tone?: 'primary' | 'danger';
    icon?: string;
    /** Read-only text the user can select and copy (e.g. a payment link the clipboard could not take). */
    copyText?: string;
}

/** One dialog at a time, rendered by <AppDialog> in the layout; replaces window.confirm/alert/prompt. */
export const dialogState = reactive<{ open: boolean; options: DialogOptions; resolve: ((ok: boolean) => void) | null }>({
    open: false, options: { title: '' }, resolve: null,
});

function show(options: DialogOptions): Promise<boolean> {
    dialogState.resolve?.(false);
    return new Promise(resolve => {
        dialogState.options = options;
        dialogState.resolve = resolve;
        dialogState.open = true;
    });
}

export function closeDialog(ok: boolean) {
    dialogState.open = false;
    dialogState.resolve?.(ok);
    dialogState.resolve = null;
}

export const confirmDialog = (options: DialogOptions) => show({ confirmLabel: 'Yes, continue', cancelLabel: 'Cancel', tone: 'primary', ...options });

export const alertDialog = (options: DialogOptions) => show({ confirmLabel: 'Close', cancelLabel: null, ...options });
