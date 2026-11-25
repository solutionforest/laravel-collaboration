import type { AnyExtension } from '@tiptap/core'
import type { ReactElement } from 'react'
import type { CollaborativeForm } from './index.js'

/** A TipTap editor for a rich text field of a collaborative form. */
export function CollaborativeEditor(props: {
    form: CollaborativeForm<any>
    field: string
    /** StarterKit by default. */
    extensions?: AnyExtension[]
    className?: string
}): ReactElement
