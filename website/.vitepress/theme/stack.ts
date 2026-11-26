import { nextTick, onMounted, ref } from 'vue'

/** The stacks a reader can pick, in the order their tabs show. */
export const stacks = { livewire: 'Livewire', react: 'React', vue: 'Vue' } as const

export type Stack = keyof typeof stacks

const key = 'laravel-collaboration:stack'

/** The reader's stack, shared by every tab group on every page. */
export const stack = ref<Stack>('livewire')

/** Bring back the stack the reader picked last time, once the page is in the browser. */
export function useStack() {
    onMounted(() => {
        const saved = localStorage.getItem(key)

        if (saved && saved in stacks) stack.value = saved as Stack
    })

    return stack
}

/** Pick a stack everywhere, keeping the tab that was clicked where it is on screen. */
export async function choose(next: Stack, tab?: HTMLElement) {
    const before = tab?.getBoundingClientRect().top

    stack.value = next

    try {
        localStorage.setItem(key, next)
    } catch {
        // Private windows may refuse storage: the choice still holds for this page.
    }

    if (tab && before !== undefined) {
        await nextTick()
        window.scrollBy(0, tab.getBoundingClientRect().top - before)
    }
}
