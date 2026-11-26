import { EditorContent, useEditor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { createElement, useRef } from 'react'
import { Collaborative } from '../tiptap/index.js'

/**
 * A TipTap editor for a rich text field of a collaborative form.
 *
 *     <CollaborativeEditor form={form} field="body" />
 *
 * It shows the field's content at once, and becomes live as soon as the
 * room has joined. Pass `extensions` to use more than StarterKit.
 *
 * @param {{form: any, field: string, extensions?: any[], images?: boolean, className?: string}} props
 */
export function CollaborativeEditor({ form, field, extensions = [StarterKit], images = false, className = '' }) {
    const { room } = form.collaboration
    const latest = useRef(form)

    latest.current = form

    // Built again once the room joins, with the room in its extensions.
    const editor = useEditor({
        extensions: [...extensions, ...(room ? [Collaborative.configure({ room, field, images })] : [])],
        content: form.data[field] || '',
        onUpdate: ({ editor: updated }) => latest.current.setData(field, updated.getJSON()),
        immediatelyRender: false,
    }, [room])

    return createElement(EditorContent, { editor, className })
}
