import { createInertiaApp } from '@inertiajs/vue3'
import { createApp, h } from 'vue'

createInertiaApp({
    resolve: (name) => import.meta.glob('./Pages/Vue/**/*.vue', { eager: true })[`./Pages/Vue/${name}.vue`],
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el)
    },
})
