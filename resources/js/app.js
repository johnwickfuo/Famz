import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

createInertiaApp({
    // The page title suffix is the company name, which the server shares on
    // every page. Nothing here may name the company.
    title: (title, page) => {
        const company = page?.props?.branding?.name ?? '';

        if (!title) {
            return company;
        }

        return company ? `${title} — ${company}` : title;
    },
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#F5B711',
        delay: 120,
    },
});
