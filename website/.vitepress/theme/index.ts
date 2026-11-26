import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import { h } from 'vue'
import HomeSections from './components/HomeSections.vue'
import Lightbox from './components/Lightbox.vue'
import './custom.css'

export default {
    extends: DefaultTheme,
    // One lightbox for every page: screenshots and doc images open larger.
    Layout: () => h(DefaultTheme.Layout, null, { 'layout-bottom': () => h(Lightbox) }),
    enhanceApp({ app }) {
        app.component('HomeSections', HomeSections)
    },
} satisfies Theme
