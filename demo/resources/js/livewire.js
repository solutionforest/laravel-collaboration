import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { Collaborative } from '@solutionforest/laravel-collaboration/tiptap'

/**
 * A TipTap editor for a Livewire component's rich text field. The package's
 * Livewire script joins the room; this waits for it, then edits through it.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('richEditor', (field) => ({
        init() {
            const start = () => window.Collaboration.whenReady(this.$el, (room) => {
                // Kept out of Alpine's reactive state: ProseMirror must not be proxied.
                const editor = new Editor({
                    element: this.$refs.editor,
                    extensions: [StarterKit, Collaborative.configure({ room, field })],
                    content: this.$wire.get(field) ?? '',
                    onUpdate: ({ editor: updated }) => this.$wire.$set(field, updated.getJSON(), false),
                })

                this.$cleanup?.(() => editor.destroy())
            })

            // The package's script may load after this one.
            const wait = () => (window.Collaboration?.whenReady ? start() : setTimeout(wait, 50))

            wait()
        },
    }))
})
