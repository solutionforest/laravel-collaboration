<script setup lang="ts">
import { computed } from 'vue'
import { choose, stacks as labels, useStack, type Stack } from '../stack'

// One example per stack. Picking a stack here picks it on every page.
const props = defineProps<{ stacks: Stack[] }>()

const stack = useStack()
const current = computed(() => (props.stacks.includes(stack.value) ? stack.value : props.stacks[0]))
</script>

<template>
    <div class="stack-tabs">
        <div class="stack-tabs-bar" role="tablist" aria-label="Your stack">
            <button
                v-for="name in stacks"
                :key="name"
                type="button"
                role="tab"
                :aria-selected="name === current"
                :class="{ active: name === current }"
                @click="choose(name, $event.currentTarget as HTMLElement)"
            >{{ labels[name] }}</button>
        </div>
        <div v-for="name in stacks" v-show="name === current" :key="name" class="stack-tabs-panel" role="tabpanel">
            <slot :name="name" />
        </div>
    </div>
</template>
