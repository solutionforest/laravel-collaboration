import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'

createInertiaApp({
    resolve: (name) => import.meta.glob('./Pages/React/**/*.jsx', { eager: true })[`./Pages/React/${name}.jsx`],
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />)
    },
})
