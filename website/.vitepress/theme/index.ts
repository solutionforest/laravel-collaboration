import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import { h } from 'vue'
import HeroStacks from './components/HeroStacks.vue'
import HomeSections from './components/HomeSections.vue'
import Lightbox from './components/Lightbox.vue'
import StackTabs from './components/StackTabs.vue'
import './custom.css'

export default {
    extends: DefaultTheme,
    // One lightbox for every page: screenshots and doc images open larger.
    // The landing page's picture switches between Livewire, React and Vue.
    Layout: () => h(DefaultTheme.Layout, null, { 'home-hero-image': () => h(HeroStacks), 'layout-bottom': () => h(Lightbox) }),
    enhanceApp({ app }) {
        app.component('HomeSections', HomeSections)
        app.component('StackTabs', StackTabs)
    },
} satisfies Theme
