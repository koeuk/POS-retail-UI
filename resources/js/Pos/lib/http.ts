import axios from 'axios';

/**
 * Client HTTP instance used by POS (handled by setupMockApi in mock/api.ts).
 */
export const http = axios.create({
    baseURL: '/pos/data',
    headers: { Accept: 'application/json' },
});

/** Raised when the session is gone and the cashier must sign in again. */
export class SessionExpired extends Error {}

/** True only when online. */
export async function isReallyOnline(): Promise<boolean> {
    if (!navigator.onLine) return false;

    try {
        await http.get('/heartbeat', { timeout: 5000, headers: { 'X-Skip-Retry': '1' } });
        return true;
    } catch {
        return false;
    }
}
