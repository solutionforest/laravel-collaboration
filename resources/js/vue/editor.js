import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { defineComponent, h, onBeforeUnmount, shallowRef } from 'vue'
import { Collaborative } from '../tiptap/index.js'

/** A TipTap editor for a rich text field: `<CollaborativeEditor :form="form" field="body" />`. */
export const CollaborativeEditor = defineComponent({
    name: 'CollaborativeEditor',
    props: {
        form: { type: Object, required: true },
        field: { type: String, required: true },
        extensions: { type: Array, default: () => [StarterKit] },
    },
    setup(props) {
        const { room } = props.form.collaboration

        const editor = shallowRef(new Editor({
            extensions: [...props.extensions, ...(room ? [Collaborative.configure({ room, field: props.field })] : [])],
            content: props.form[props.field] || '',
            onUpdate: ({ editor: updated }) => (props.form[props.field] = updated.getJSON()),
        }))

        onBeforeUnmount(() => editor.value.destroy())

        return () => h(EditorContent, { editor: editor.value })
    },
})
