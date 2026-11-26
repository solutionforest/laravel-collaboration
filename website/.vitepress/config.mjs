import { readFileSync } from 'node:fs'
import { defineConfig } from 'vitepress'

// Written by scripts/sync.mjs from the README, so the sidebar follows it.
const pages = JSON.parse(readFileSync(new URL('./pages.json', import.meta.url), 'utf8'))

export default defineConfig({
    title: 'Laravel Collaboration',
    description: 'Google Docs-style collaboration for Laravel: Livewire, Inertia React and Vue, TipTap.',
    cleanUrls: true,
    themeConfig: {
        nav: [
            { text: 'Guide', link: '/guide/introduction' },
            { text: 'GitHub', link: 'https://github.com/solutionforest/laravel-collaboration' },
        ],
        sidebar: [{ text: 'Guide', items: pages }],
        search: { provider: 'local' },
        outline: [2, 3],
    },
})
