import { createApp, h } from 'vue';
import { createInertiaApp, Link, Head, useForm } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

createInertiaApp({
  title: (title) => `${title} · Campus Digital`,
  resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
  setup({ el, App, props, plugin }) {
    const app = createApp({ render: () => h(App, props) });
    app.use(plugin);
    app.component('Link', Link);
    app.component('Head', Head);
    app.mount(el);
  },
  progress: { color: '#2563eb' },
});
