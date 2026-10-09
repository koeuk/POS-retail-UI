/// <reference types="vite/client" />

/*
 * Vite 6 ships client.d.ts as a global script rather than a module, so the
 * `declare module 'vite/client'` augmentation that used to live in app.ts no
 * longer compiles. Declaring the interfaces globally is the supported route.
 */
interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
    readonly [key: string]: string | boolean | undefined;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
