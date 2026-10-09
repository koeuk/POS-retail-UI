import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * The page size currently in the URL, for filter reloads to carry along.
 * Inertia's router.get replaces the whole query string, so a reload that
 * forgets this key silently resets the reader's chosen size — Pagination.vue
 * preserves the pages' filters when writing per_page, and this returns the
 * favour.
 */
export function currentPerPage(): string | undefined {
    return new URLSearchParams(window.location.search).get('per_page') ?? undefined;
}

/**
 * Renderable src for a stored image reference, which is either a public-disk
 * path (products/abc.jpg) or a full external URL pasted as a link.
 */
export function imageSrc(src: string | null | undefined): string | undefined {
    if (!src) return undefined;

    return /^https?:\/\//.test(src) ? src : `/storage/${src}`;
}
