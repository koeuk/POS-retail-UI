import '../css/app.css';

import { createInertiaApp, resolvePageComponent } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import { initializeTheme } from './composables/useAppearance';
import { initTelegram } from './composables/useTelegram';
import { setupMockApi } from './mock/api';
import { Ziggy } from './ziggy';

// Initialize mock API interceptors for offline POS and debt lookups
setupMockApi();

const appName = import.meta.env.VITE_APP_NAME || 'POS Retail';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, Ziggy)
            // Pinia backs the POS cart; the admin pages do not use it.
            .use(createPinia())
            .mount(el);
    },
    progress: {
        // Matches --primary so the loading bar reads as part of the theme.
        color: '#1c6949',
    },
});

// Set light / dark mode on page load
initializeTheme();

// Telegram Web App viewport helper
initTelegram();
