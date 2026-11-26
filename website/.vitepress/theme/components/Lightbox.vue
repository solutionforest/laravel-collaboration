<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vitepress'

// Screenshots on the home page and images in the docs open larger, in a dialog.
// Without JavaScript, the gallery's links still open the image itself.
const SELECTOR = '.home-shot img, .vp-doc img'

const dialog = ref<HTMLDialogElement | null>(null)
const images = ref<HTMLImageElement[]>([])
const index = ref(0)
const current = computed(() => images.value[index.value])
const caption = computed(() => {
    const image = current.value

    if (!image) return ''

    const figure = image.closest('figure')
    const figcaption = figure?.querySelector('figcaption strong') ?? figure?.querySelector('figcaption')

    return (figcaption?.textContent || image.alt || '').trim()
})

const zoomable = (image: Element | null): image is HTMLImageElement => image instanceof HTMLImageElement && image.matches(SELECTOR) && !image.closest('.no-lightbox')

/** Shown on screen: a diagram's light or dark picture, not both. */
const visible = (image: HTMLImageElement): boolean => image.getClientRects().length > 0

function open(image: HTMLImageElement): void {
    // Only the images of one kind on the page: the gallery's, or the doc's.
    const scope = image.closest('.home-gallery') ? '.home-shot img' : '.vp-doc img'

    images.value = Array.from(document.querySelectorAll<HTMLImageElement>(scope)).filter((candidate) => zoomable(candidate) && visible(candidate))
    index.value = Math.max(0, images.value.indexOf(image))
    dialog.value?.showModal()
}

function close(): void {
    dialog.value?.close()
}

function step(by: number): void {
    const count = images.value.length

    if (count > 1) index.value = (index.value + by + count) % count
}

function onClick(event: MouseEvent): void {
    // Opened with a modifier key: the browser's own behaviour, such as a new tab.
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return

    const target = event.target as Element | null
    const image = target?.closest('a')?.querySelector('img') ?? target

    if (!zoomable(image)) return

    event.preventDefault()
    open(image)
}

function onKey(event: KeyboardEvent): void {
    if (event.key !== 'Enter' && event.key !== ' ') return

    const target = event.target as Element | null

    if (zoomable(target)) {
        event.preventDefault()
        open(target)
    }
}

function onDialogKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowRight') step(1)
    if (event.key === 'ArrowLeft') step(-1)
}

/** Doc images are not links: make them reachable by keyboard, and say what a click does. */
function prepare(): void {
    for (const image of document.querySelectorAll<HTMLImageElement>('.vp-doc img')) {
        if (!zoomable(image) || image.hasAttribute('tabindex')) continue

        image.tabIndex = 0
        image.setAttribute('aria-haspopup', 'dialog')
        image.title ||= 'Click to enlarge'
    }
}

const route = useRoute()

onMounted(() => {
    document.addEventListener('click', onClick)
    document.addEventListener('keydown', onKey)
    prepare()
})

onBeforeUnmount(() => {
    document.removeEventListener('click', onClick)
    document.removeEventListener('keydown', onKey)
})

// Another page: close, and prepare its images once they are rendered.
watch(
    () => route.path,
    () => {
        close()
        nextTick(prepare)
    },
)
</script>

<template>
    <dialog ref="dialog" class="lightbox" aria-label="Enlarged image" @click.self="close" @keydown="onDialogKey">
        <figure v-if="current" class="lightbox-figure">
            <img :src="current.currentSrc || current.src" :alt="current.alt" />
            <figcaption>
                <span>{{ caption }}</span>
                <span v-if="images.length > 1" class="lightbox-count">{{ index + 1 }} / {{ images.length }}</span>
            </figcaption>
        </figure>
        <button v-if="images.length > 1" type="button" class="lightbox-nav lightbox-prev" aria-label="Previous image" @click="step(-1)">‹</button>
        <button v-if="images.length > 1" type="button" class="lightbox-nav lightbox-next" aria-label="Next image" @click="step(1)">›</button>
        <button type="button" class="lightbox-close" aria-label="Close" autofocus @click="close">✕</button>
    </dialog>
</template>
