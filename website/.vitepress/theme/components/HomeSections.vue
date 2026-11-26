<script setup lang="ts">
import Family from './Family.vue'
import { withBase } from 'vitepress'
import { useStack } from '../stack'

const stack = useStack()

const gallery = [
    { file: 'three-stacks.png', title: 'Three stacks, one article', text: 'Alice in React, Bob in Vue and Chloe in Livewire, at the same moment.' },
    { file: 'react.png', title: 'Inertia + React', text: 'useCollaborativeForm(), field props, avatars and a TipTap editor.' },
    { file: 'vue.png', title: 'Inertia + Vue', text: 'The same form with v-collaborate, next to each v-model.' },
    { file: 'livewire.png', title: 'Livewire', text: 'wire:model inputs become collaborative; no JavaScript to write.' },
]
</script>

<template>
    <div class="home-sections">
        <section id="demo" class="home-section">
            <p class="home-eyebrow">Real session</p>
            <h2>Three browsers, one article</h2>
            <p class="home-lead">Alice types in React, Bob in Vue and Chloe in Livewire: their words merge, their carets show, and one save keeps everybody's work.</p>
            <div class="home-frame">
                <video :src="withBase('/media/demo.mp4')" controls muted playsinline preload="metadata" :poster="withBase('/media/three-stacks.png')" aria-label="Demo: three people editing one article from Livewire, React and Vue" />
            </div>
        </section>

        <section class="home-section">
            <p class="home-eyebrow">Quick start</p>
            <h2>Three steps</h2>
            <div class="home-steps">
                <div class="home-step">
                    <span class="home-step-number">1</span>
                    <h3>Install</h3>
                    <pre v-pre><code>composer require solutionforest/laravel-collaboration
php artisan collaboration:install</code></pre>
                </div>
                <div class="home-step">
                    <span class="home-step-number">2</span>
                    <h3>Say which fields</h3>
                    <pre v-pre><code>class Post extends Model
{
    use Collaborative;

    protected array $collaborative = [
        'title', 'body' => FieldKind::RichText,
    ];
}</code></pre>
                </div>
                <div class="home-step">
                    <span class="home-step-number">3</span>
                    <h3>Use it</h3>
                    <pre v-if="stack === 'livewire'" v-pre><code>&lt;x-collaboration::room :room="$this->collaborationRoom()" /&gt;

&lt;input wire:model="title"&gt;
&lt;x-collaboration::editor field="body" /&gt;</code></pre>
                    <pre v-else-if="stack === 'react'" v-pre><code>const form = useCollaborativeForm(collaboration, data)

&lt;input {...form.field('title')} /&gt;
&lt;CollaborativeEditor form={form} field="body" /&gt;</code></pre>
                    <pre v-else v-pre><code>const form = useCollaborativeForm(() => props.collaboration, data)

&lt;input v-model="form.title" v-collaborate="form.field('title')"&gt;
&lt;CollaborativeEditor :form="form" field="body" /&gt;</code></pre>
                </div>
            </div>
            <p class="home-more">
                <a :href="withBase('/guide/first-page')">Follow the step-by-step example page →</a>
                &nbsp;·&nbsp;
                <a :href="withBase('/guide/custom-components')">Make your own component live →</a>
            </p>
        </section>

        <section class="home-section">
            <p class="home-eyebrow">Screenshots</p>
            <h2>Taken from real sessions</h2>
            <p class="home-lead">Every image is recorded from three browsers on the demo app by a script, so what you see is what the package does.</p>
            <div class="home-gallery">
                <figure v-for="shot in gallery" :key="shot.file" class="home-shot">
                    <a :href="withBase(`/media/${shot.file}`)" target="_blank" rel="noopener">
                        <img :src="withBase(`/media/${shot.file}`)" :alt="shot.title" loading="lazy" />
                    </a>
                    <figcaption>
                        <strong>{{ shot.title }}</strong>
                        <span>{{ shot.text }}</span>
                    </figcaption>
                </figure>
            </div>
        </section>

        <Family current="laravel" />

        <section class="home-section home-cta">
            <h2>Requirements</h2>
            <p class="home-lead">PHP 8.3+, Laravel 12 or 13, and Livewire 4 or Inertia 3 with React 19 or Vue 3.5. MIT licensed.</p>
            <div class="home-cta-actions">
                <a class="home-button home-button-brand" :href="withBase('/getting-started')">Get started</a>
                <a class="home-button" href="https://github.com/solutionforest/laravel-collaboration">Star on GitHub</a>
            </div>
        </section>
    </div>
</template>
