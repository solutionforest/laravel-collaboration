import type { AnyExtension } from '@tiptap/core'
import type { DefineComponent } from 'vue'

/** A TipTap editor for a rich text field of a collaborative form. */
export const CollaborativeEditor: DefineComponent<{
    form: { collaboration: unknown }
    field: string
    /** StarterKit by default. */
    extensions?: AnyExtension[]
    images?: boolean
}>
