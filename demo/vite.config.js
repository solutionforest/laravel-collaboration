import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/react.jsx', 'resources/js/vue.js'],
            refresh: true,
        }),
        react(),
        vue(),
        tailwindcss(),
    ],
    resolve: {
        // The package is linked from the repository, which has dependencies of its own: use the app's.
        dedupe: ['react', 'react-dom', 'vue', '@inertiajs/react', '@inertiajs/vue3', '@tiptap/core', '@tiptap/pm', '@tiptap/react', '@tiptap/vue-3', '@tiptap/starter-kit'],
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
})
