import { defineConfig } from 'vite'

// Comments and history for Livewire, in a script of its own, loaded
// only by pages that render `<x-collaboration::comments>` or `history`.
export default defineConfig({
    build: {
        outDir: 'resources/dist',
        emptyOutDir: false,
        lib: {
            entry: 'resources/js/livewire/features.js',
            name: 'CollaborationFeatures',
            formats: ['iife'],
            fileName: () => 'livewire-features.js',
        },
    },
})
