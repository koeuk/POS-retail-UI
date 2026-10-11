import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

/**
 * What the signed-in user may do, for hiding controls they cannot use.
 *
 * This is a courtesy, never the wall: the route middleware and the policies
 * are what actually refuse the action. A button hidden here that somehow
 * gets clicked still 403s on the server.
 */
export function usePermissions() {
    const page = usePage<SharedData>();

    /** May they open the area at all? */
    const canAccess = (area: string) => !!page.props.auth.can[area];

    /** May they take this action inside it? */
    const may = (area: string, action: 'view' | 'create' | 'update' | 'delete') => {
        const act = page.props.auth.actions?.[area] as Record<string, boolean> | undefined;
        if (!act) return false;
        if (action === 'update') {
            return !!(act.update ?? act.edit);
        }
        return !!act[action];
    };

    return { canAccess, may };
}
