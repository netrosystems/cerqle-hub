import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { toast } from 'sonner';

/**
 * Turns Laravel's flash messages into toasts.
 *
 * Controllers already redirect with ->with('success', ...) and the Inertia
 * middleware already shares it as props.flash, but nothing rendered it, so
 * every save in the app looked like nothing had happened.
 *
 * Call it from a layout that mounts <Toaster />, not from individual pages, so
 * one save cannot raise the same toast several times.
 */
export default function useFlashToasts() {
    const { flash } = usePage().props;
    // Inertia keeps props between visits, so the same message can arrive again
    // on an unrelated re-render. Showing it once per visit is what a person
    // expects: a toast that reappears looks like a second save.
    const lastShown = useRef({ success: null, error: null });

    useEffect(() => {
        if (!flash) {
            return;
        }

        if (flash.success && flash.success !== lastShown.current.success) {
            lastShown.current.success = flash.success;
            toast.success(flash.success);
        }
        if (!flash.success) {
            lastShown.current.success = null;
        }

        if (flash.error && flash.error !== lastShown.current.error) {
            lastShown.current.error = flash.error;
            toast.error(flash.error);
        }
        if (!flash.error) {
            lastShown.current.error = null;
        }
    }, [flash]);
}
