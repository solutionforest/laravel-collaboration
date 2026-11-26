import { toIndex, toOffset } from '../crdt/id.js'
import { CaretLayer } from '../ui/carets.js'
import { History } from './history.js'
import { lockWhileEditing } from './lock.js'

/**
 * Makes an `<input>` or `<textarea>` collaborative: what is typed is shared,
 * what others type appears without moving the caret, their carets show, and
 * Ctrl+Z undoes only your own typing.
 *
 *     const unbind = bindTextField(room, 'title', input, { onValueChange: (value) => form.title = value })
 *
 * @param {import('../core/room.js').Room} room
 * @param {string} field
 * @param {HTMLInputElement | HTMLTextAreaElement} element
 * @param {{onValueChange?: (value: string) => void, onRemoteChange?: (value: string) => void}} [options]
 *     `onValueChange` tells the framework's own state about every value the binding writes: others' edits, undo and redo
 * @returns {() => void} what makes the element an ordinary input again
 */
export function bindTextField(room, field, element, { onValueChange, onRemoteChange } = {}) {
    const events = new AbortController()
    const carets = new CaretLayer(room, field, element)
    const history = new History()
    const notify = onValueChange ?? onRemoteChange
    let composing = false
    /** @type {{anchor: Id | null, head: Id | null, direction: HTMLInputElement['selectionDirection']} | null} */
    let saved = null

    const isFocused = () => document.activeElement === element

    const selection = () => ({
        anchor: toIndex(element.value, element.selectionStart ?? 0),
        head: toIndex(element.value, element.selectionEnd ?? 0),
    })

    /** @param {string} value */
    const write = (value) => {
        element.value = value
        notify?.(value)
    }

    const show = () => {
        const value = room.value(field) ?? ''

        if (element.value !== value) {
            history.remote(element.value, value)
            write(value)
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
        reveal: () => element.scrollIntoView({ block: 'center', behavior: 'smooth' }),
    })

    const { signal } = events

    /** Shares what was typed, and remembers it for undo. */
    const typed = () => {
        const caret = selection().head

        if (room.isLiveField(field)) history.local(room.value(field) ?? '', element.value, caret)

        room.change(field, element.value, caret)
    }

    /** @param {'undo' | 'redo'} direction */
    const travel = (direction) => {
        const result = history[direction](element.value)

        if (!result) return

        write(result.value)
        room.change(field, result.value, result.caret)

        const offset = toOffset(result.value, result.caret)

        element.setSelectionRange(offset, offset)
    }

    element.addEventListener('input', () => {
        if (!composing) typed()
    }, { signal })
    element.addEventListener('compositionstart', () => (composing = true), { signal })
    element.addEventListener('compositionend', () => {
        composing = false
        typed()
    }, { signal })

    // Ctrl+Z, Ctrl+Shift+Z, Ctrl+Y, and Undo in the context menu: yours only.
    element.addEventListener('keydown', (event) => {
        if (!(event.ctrlKey || event.metaKey) || event.altKey || !room.isLiveField(field)) return

        const key = event.key.toLowerCase()
        const direction = key === 'z' ? (event.shiftKey ? 'redo' : 'undo') : key === 'y' ? 'redo' : null

        if (!direction) return

        event.preventDefault()
        travel(direction)
    }, { signal })
    element.addEventListener('beforeinput', (event) => {
        if (!room.isLiveField(field) || !['historyUndo', 'historyRedo'].includes(event.inputType)) return

        event.preventDefault()
        travel(event.inputType === 'historyUndo' ? 'undo' : 'redo')
    }, { signal })
    element.addEventListener('focus', () => room.focus(field), { signal })
    element.addEventListener('blur', () => room.blur(field), { signal })

    for (const name of ['select', 'keyup', 'mouseup']) element.addEventListener(name, () => room.cursorMoved(), { signal })

    element.addEventListener('scroll', () => carets.draw(), { signal })

    const stopListening = room.on('people', () => carets.draw())
    const unlock = lockWhileEditing(room, field, element)

    if (room.isReady) show()

    return () => {
        events.abort()
        stopListening()
        unlock()
        carets.destroy()
        unbind()
    }
}
