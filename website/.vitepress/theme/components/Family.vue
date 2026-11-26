<script setup lang="ts">
// The two packages, side by side on both sites: the same engine, for Filament panels or for any Laravel app.
defineProps<{ current: 'filament' | 'laravel' }>()

const packages = [
    {
        key: 'filament',
        name: 'Filament Collaboration',
        stack: 'Filament 5 panels',
        text: 'Every field of a Filament resource, live, with no form rewritten. Presence on tables, comments and history in the panel.',
        site: 'https://solutionforest.github.io/filament-collaboration/',
        repo: 'https://github.com/solutionforest/filament-collaboration',
    },
    {
        key: 'laravel',
        name: 'Laravel Collaboration',
        stack: 'Livewire · Inertia + React · Inertia + Vue',
        text: 'The engine both are built on: shared drafts, presence and sync, with a trait for Livewire and a hook for React and Vue.',
        site: 'https://solutionforest.github.io/laravel-collaboration/',
        repo: 'https://github.com/solutionforest/laravel-collaboration',
    },
] as const
</script>

<template>
    <section class="home-section">
        <p class="home-eyebrow">One engine, two packages</p>
        <h2>Pick the one for your app</h2>
        <p class="home-lead">Filament Collaboration is built on Laravel Collaboration: the same merge engine, drafts and presence, tested together on every change.</p>
        <div class="home-family">
            <div v-for="item in packages" :key="item.key" class="home-family-card" :class="{ current: item.key === current }">
                <p class="home-family-stack">{{ item.stack }}</p>
                <h3>{{ item.name }} <span v-if="item.key === current" class="home-family-here">You are here</span></h3>
                <p>{{ item.text }}</p>
                <div class="home-family-links">
                    <a v-if="item.key !== current" :href="item.site">Documentation →</a>
                    <a :href="item.repo">GitHub →</a>
                </div>
            </div>
        </div>
    </section>
</template>
