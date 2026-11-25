import { defineConfig } from 'vite'

// Builds the Livewire adapter into one script the package serves itself,
// so a Livewire app needs no JavaScript build step. Inertia apps import the sources.
export default defineConfig({
    build: {
        outDir: 'resources/dist',
        emptyOutDir: false,
        lib: {
            entry: 'resources/js/livewire/index.js',
            name: 'Collaboration',
            formats: ['iife'],
            fileName: () => 'livewire.js',
        },
    },
})
