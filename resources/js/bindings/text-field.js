import { toIndex, toOffset } from '../crdt/id.js'
import { CaretLayer } from '../ui/carets.js'

/**
 * Makes an `<input>` or `<textarea>` collaborative: what is typed is shared,
 * what others type appears without moving the caret, and their carets show.
 *
 *     const unbind = bindTextField(room, 'title', input, { onRemoteChange: (value) => form.title = value })
 *
 * @param {import('../core/room.js').Room} room
 * @param {string} field
 * @param {HTMLInputElement | HTMLTextAreaElement} element
 * @param {{onRemoteChange?: (value: string) => void}} [options] tells the framework's own state about others' edits
 * @returns {() => void} what makes the element an ordinary input again
 */
export function bindTextField(room, field, element, { onRemoteChange } = {}) {
    const events = new AbortController()
    const carets = new CaretLayer(room, field, element)
    let composing = false
    /** @type {{anchor: Id | null, head: Id | null, direction: HTMLInputElement['selectionDirection']} | null} */
    let saved = null

    const isFocused = () => document.activeElement === element

    const selection = () => ({
        anchor: toIndex(element.value, element.selectionStart ?? 0),
        head: toIndex(element.value, element.selectionEnd ?? 0),
    })

    const show = () => {
        const value = room.value(field) ?? ''

        if (element.value !== value) {
            element.value = value
            onRemoteChange?.(value)
        }

        if (saved && isFocused()) {
            const anchor = toOffset(value, room.indexOf(field, saved.anchor) ?? 0)
            const head = toOffset(value, room.indexOf(field, saved.head) ?? 0)

            element.setSelectionRange(Math.min(anchor, head), Math.max(anchor, head), saved.direction ?? 'none')
        }

        saved = null
        carets.draw()
    }

    const unbind = room.bind(field, {
        get isBusy() {
            return composing
        },
        capture() {
            if (!isFocused()) return

            const { anchor, head } = selection()

            saved = { anchor: room.idAt(field, anchor), head: room.idAt(field, head), direction: element.selectionDirection }
        },
        render: show,
        cursor: () => (isFocused() ? selection() : null),
    })

    const { signal } = events

    element.addEventListener('input', () => {
        if (!composing) room.change(field, element.value, selection().head)
    }, { signal })
    element.addEventListener('compositionstart', () => (composing = true), { signal })
    element.addEventListener('compositionend', () => {
        composing = false
        room.change(field, element.value, selection().head)
    }, { signal })
    element.addEventListener('focus', () => room.focus(field), { signal })
    element.addEventListener('blur', () => room.blur(field), { signal })

    for (const name of ['select', 'keyup', 'mouseup']) element.addEventListener(name, () => room.cursorMoved(), { signal })

    element.addEventListener('scroll', () => carets.draw(), { signal })

    const stopListening = room.on('people', () => carets.draw())

    if (room.isReady) show()

    return () => {
        events.abort()
        stopListening()
        carets.destroy()
        unbind()
    }
}
