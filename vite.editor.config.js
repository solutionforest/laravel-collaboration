import { defineConfig } from 'vite'

// The Livewire rich text editor: TipTap, in a script of its own, loaded
// only by pages that render `<x-collaboration::editor>`.
export default defineConfig({
    build: {
        outDir: 'resources/dist',
        emptyOutDir: false,
        lib: {
            entry: 'resources/js/livewire/editor.js',
            name: 'CollaborationEditor',
            formats: ['iife'],
            fileName: () => 'livewire-editor.js',
        },
    },
})
