import { EditorContent, useEditor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { createElement, useEffect } from 'react'
import { Collaborative } from '../tiptap/index.js'

/**
 * A TipTap editor for a rich text field of a collaborative form.
 *
 *     <CollaborativeEditor form={form} field="body" />
 *
 * @param {{form: any, field: string, extensions?: any[], className?: string}} props
 */
export function CollaborativeEditor({ form, field, extensions = [StarterKit], className = '' }) {
    const room = form.room

    const editor = useEditor({
        extensions: [...extensions, ...(room ? [Collaborative.configure({ room, field })] : [])],
        content: form.data[field] || '',
        onUpdate: ({ editor: updated }) => form.setData(field, updated.getJSON()),
        immediatelyRender: false,
    }, [room])

    useEffect(() => () => editor?.destroy(), [editor])

    return createElement(EditorContent, { editor, className })
}
