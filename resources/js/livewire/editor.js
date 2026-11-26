import { Editor } from '@tiptap/core'
import Image from '@tiptap/extension-image'
import StarterKit from '@tiptap/starter-kit'
import { Collaborative } from '../tiptap/index.js'

/**
 * The script of `<x-collaboration::editor>`: a TipTap editor for a
 * Livewire component's rich text field, joined to the component's room.
 * Served by the package, so a Livewire app needs no build step for it either.
 */

/** @type {WeakMap<HTMLElement, Editor>} */
const editors = new WeakMap()

function mount() {
    for (const element of document.querySelectorAll(/** @type {'div'} */ ('[data-collab-editor]'))) {
        if (editors.has(element) || element.dataset.collabEditorStarting) continue

        const component = element.closest('[wire\\:id]')
        const wire = component ? window.Livewire?.find(component.getAttribute('wire:id')) : null
        const field = element.dataset.collabEditor ?? ''
        const path = element.dataset.statePath ? `${element.dataset.statePath}.${field}` : field

        if (!wire || !window.Collaboration?.whenReady) continue

        element.dataset.collabEditorStarting = '1'

        window.Collaboration.whenReady(element, (room) => {
            const editor = new Editor({
                element,
                extensions: [StarterKit, Image, Collaborative.configure({ room, field, images: element.dataset.images === 'true' })],
                content: wire.get(path) ?? '',
                onUpdate: ({ editor: updated }) => wire.$set(path, updated.getJSON(), false),
            })

            editors.set(element, editor)
        })
    }
}

document.addEventListener('livewire:initialized', mount)
document.addEventListener('livewire:navigated', mount)
setInterval(mount, 500)
