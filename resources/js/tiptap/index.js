import { Extension } from '@tiptap/core'
import { Plugin, PluginKey, TextSelection } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import { addStyles } from '../ui/styles.js'
import { buildDocument, replacement, toDraft, toEditor } from './prosemirror.js'

const key = new PluginKey('collaborative')

/**
 * Makes a TipTap editor collaborative: what is typed is shared, what others
 * type appears without moving the caret, and their carets show.
 *
 *     new Editor({ extensions: [StarterKit, Collaborative.configure({ room, field: 'body' })] })
 */
export const Collaborative = Extension.create({
    name: 'collaborative',

    addOptions() {
        return {
            /** @type {import('../core/room.js').Room | null} */
            room: null,
            field: '',
        }
    },

    addProseMirrorPlugins() {
        const { room, field } = this.options

        if (!room || !field) return []

        addStyles()

        let applying = false

        return [
            new Plugin({
                key,
                // Bound while the editor is on the page: an editor built but never shown must not take the field.
                view(view) {
                    /** @type {{anchor: Id | null, head: Id | null} | null} */
                    let saved = null

                    const unbind = room.bind(field, {
                        capture() {
                            const { anchor, head } = view.state.selection

                            saved = { anchor: room.idAt(field, toDraft(view.state.doc, anchor)), head: room.idAt(field, toDraft(view.state.doc, head)) }
                        },
                        render() {
                            const value = room.value(field)

                            if (!value || view.isDestroyed) return

                            const transaction = replacement(view.state, buildDocument(view.state.schema, value))

                            if (saved && view.hasFocus()) {
                                const doc = transaction.doc
                                const anchor = toEditor(doc, room.indexOf(field, saved.anchor) ?? 0)
                                const head = toEditor(doc, room.indexOf(field, saved.head) ?? 0)

                                transaction.setSelection(TextSelection.between(doc.resolve(anchor), doc.resolve(head)))
                            }

                            saved = null
                            applying = true
                            view.dispatch(transaction.setMeta('addToHistory', false).setMeta(key, 'remote'))
                            applying = false
                        },
                        cursor() {
                            if (!view.hasFocus()) return null

                            const { anchor, head } = view.state.selection

                            return { anchor: toDraft(view.state.doc, anchor), head: toDraft(view.state.doc, head) }
                        },
                    })

                    const stopListening = room.on('people', () => {
                        if (!view.isDestroyed) view.dispatch(view.state.tr.setMeta(key, 'people'))
                    })

                    // The draft wins over the content the editor started with.
                    if (room.isReady) queueMicrotask(() => room.bindings.get(field)?.render?.())

                    return {
                        destroy() {
                            stopListening()
                            unbind()
                        },
                    }
                },
                props: {
                    decorations: (state) => carets(room, field, state.doc),
                    handleDOMEvents: {
                        focus: () => (room.focus(field), false),
                        blur: () => (room.blur(field), false),
                    },
                },
                appendTransaction(transactions, _old, state) {
                    const isLocal = transactions.some((transaction) => transaction.docChanged && !transaction.getMeta(key))

                    if (isLocal && !applying) room.change(field, state.doc.toJSON(), toDraft(state.doc, state.selection.head))
                    else if (transactions.some((transaction) => transaction.selectionSet)) room.cursorMoved()

                    return null
                },
            }),
        ]
    },
})

/**
 * Other people's carets and selections, drawn into the document.
 *
 * @param {import('../core/room.js').Room} room
 * @param {string} field
 * @param {import('@tiptap/pm/model').Node} doc
 */
function carets(room, field, doc) {
    const decorations = room.carets(field).flatMap(({ person, anchor, head }) => {
        const from = toEditor(doc, Math.min(anchor, head))
        const to = toEditor(doc, Math.max(anchor, head))
        const at = toEditor(doc, head)

        const caret = Decoration.widget(at, () => {
            const element = document.createElement('span')
            const label = document.createElement('span')

            element.className = 'collab-pm-caret'
            element.style.borderColor = person.color
            label.textContent = person.name
            label.style.background = person.color
            element.append(label)

            return element
        }, { key: `caret-${person.client}`, side: 1 })

        return from === to ? [caret] : [Decoration.inline(from, to, { style: `background: ${person.color}33` }), caret]
    })

    return DecorationSet.create(doc, decorations)
}

export { buildDocument, replacement, toDraft, toEditor }
