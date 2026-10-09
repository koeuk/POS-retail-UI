import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
import { initTelegram } from './composables/useTelegram';

const appName = import.meta.env.VITE_APP_NAME || 'POS Retail';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            // Pinia backs the POS cart; the admin pages do not use it.
            .use(createPinia())
            .mount(el);
    },
    progress: {
        // Matches --primary so the loading bar reads as part of the theme.
        color: '#1c6949',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// Viewport height, safe-area insets and the native back button, when the app
// is running inside Telegram. A no-op in a normal browser.
initTelegram();
