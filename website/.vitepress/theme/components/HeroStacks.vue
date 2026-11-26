<script setup lang="ts">
import { withBase } from 'vitepress'
import { choose, stacks, useStack, type Stack } from '../stack'

// The landing page's picture: the same article, in the reader's stack.
const stack = useStack()
const names = Object.keys(stacks) as Stack[]
</script>

<template>
    <div class="hero-stacks">
        <div class="stack-tabs-bar" role="tablist" aria-label="Your stack">
            <button
                v-for="name in names"
                :key="name"
                type="button"
                role="tab"
                :aria-selected="name === stack"
                :class="{ active: name === stack }"
                @click="choose(name)"
            >{{ stacks[name] }}</button>
        </div>
        <img
            v-for="name in names"
            v-show="name === stack"
            :key="name"
            :src="withBase(`/media/${name}.png`)"
            :alt="`An article being edited by three people, in ${stacks[name]}`"
            width="760"
            height="980"
        >
    </div>
</template>
