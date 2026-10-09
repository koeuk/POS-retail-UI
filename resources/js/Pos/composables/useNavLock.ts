import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * Locks app navigation while the POS has unsynced work.
 *
 * The build plan is explicit that Inertia must not be able to navigate away
 * from /pos while offline. Leaving would unload the only page that owns the
 * flush loop, so queued sales would sit in Dexie untouched until someone
 * happened to reopen the till — and on a shared tablet that might be days.
 *
 * The sales themselves are never at risk: they are in IndexedDB either way.
 * What is at risk is them being *forgotten*, which is why the door closes.
 */
export const useNavLock = defineStore('nav-lock', () => {
    const locked = ref(false);
    const reason = ref<string | null>(null);

    function lock(why: string) {
        locked.value = true;
        reason.value = why;
    }

    function unlock() {
        locked.value = false;
        reason.value = null;
    }

    return { locked, reason, lock, unlock };
});
