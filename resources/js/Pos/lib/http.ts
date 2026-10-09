import axios, { AxiosError } from 'axios';

/**
 * The only way /pos talks to the server.
 *
 * Inertia's router is deliberately not used inside the POS: it returns pages
 * and redirects, and neither can be stored in Dexie and replayed hours later.
 */
export const http = axios.create({
    baseURL: '/pos/data',
    headers: { Accept: 'application/json' },

    // Axios 1.x will not attach the X-XSRF-TOKEN header without this, and
    // every route in this app lives behind web.php's CSRF middleware.
    withCredentials: true,
    withXSRFToken: true,
});

/** Raised when the session is gone and the cashier must sign in again. */
export class SessionExpired extends Error {}

let refreshing: Promise<void> | null = null;

/**
 * Refresh the XSRF-TOKEN cookie. Any GET through the web middleware reissues
 * it; heartbeat is the cheapest one and doubles as a connectivity probe.
 */
async function refreshCsrf(): Promise<void> {
    refreshing ??= http
        .get('/heartbeat', { headers: { 'X-Skip-Retry': '1' } })
        .then(() => undefined)
        .finally(() => {
            refreshing = null;
        });

    return refreshing;
}

/*
 * A tablet offline for hours comes back to an expired CSRF token. Rather than
 * failing the flush — which would leave real money stuck in the queue — refresh
 * the token once and retry. A 401 means the session itself is gone, which only
 * a human can fix, so it surfaces as SessionExpired and the queue is kept.
 */
http.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
        const status = error.response?.status;
        const config = error.config;

        if (status === 419 && config && !config.headers?.['X-Skip-Retry']) {
            await refreshCsrf();
            config.headers.set('X-Skip-Retry', '1');
            return http.request(config);
        }

        if (status === 401) {
            return Promise.reject(new SessionExpired('Session expired — sign in to sync.'));
        }

        return Promise.reject(error);
    },
);

/** True only when the server actually answered — navigator.onLine lies on captive wifi. */
export async function isReallyOnline(): Promise<boolean> {
    if (!navigator.onLine) return false;

    try {
        await http.get('/heartbeat', { timeout: 5000, headers: { 'X-Skip-Retry': '1' } });
        return true;
    } catch {
        return false;
    }
}
