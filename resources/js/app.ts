import '../css/app.css';

import { createPinia } from 'pinia';
import { createApp } from 'vue';
import { initializeTheme } from './composables/useAppearance';
import { initTelegram } from './composables/useTelegram';
import { setupMockApi } from './mock/api';
import { createInertiaApp } from './mock/inertia';

// Initialize mock API interceptors for offline POS and debt lookups
setupMockApi();

const appName = import.meta.env.VITE_APP_NAME || 'POS Retail';

// Set light / dark mode on page load
initializeTheme();

// Telegram Web App viewport helper
initTelegram();

// Bootstrap standalone SPA with mock Inertia adapter
createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => import(`./pages/${name}.vue`),
    setup({ el, App, props, plugin }) {
        const app = createApp(App, props);
        app.use(plugin);
        app.use(createPinia());
        app.mount(el);
    },
    progress: {
        color: '#1c6949',
    },
});
