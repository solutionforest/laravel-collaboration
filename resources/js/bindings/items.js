import { toIndex, toOffset } from '../crdt/id.js'
import { toKeyed, toList } from '../core/items.js'
import { isTextInput } from './lock.js'

/**
 * Makes a repeater collaborative: items added, removed and moved by anyone
 * show for everybody, and each sub-field input is bound as it appears.
 * Text sub-fields keep the caret as others type; the rest take the latest value.
 *
 *     const items = bindItems(room, 'lines', { onValueChange: (list) => form.lines = list })
 *     items.attach(line.key, 'title', input)
 *     items.change(listOps.add(form.lines))
 *
 * @param {import('../core/room.js').Room} room
 * @param {string} field
 * @param {{onValueChange?: (list: import('../core/items.js').Item[]) => void}} [options]
 */
export function bindItems(room, field, { onValueChange } = {}) {
    /** @type {Map<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement, {key: string, path: string}>} */
    const inputs = new Map()
    /** @type {{element: HTMLInputElement | HTMLTextAreaElement, key: string, path: string, anchor: Id | null, head: Id | null} | null} */
    let saved = null

    const keyed = () => /** @type {Record<string, Record<string, any>>} */ (room.value(field) ?? {})
    const sequence = (/** @type {string} */ key, /** @type {string} */ path) => room.document?.list(field)?.sequence(key, path) ?? null

    const unbind = room.bind(field, {
        capture() {
            const element = /** @type {HTMLInputElement | null} */ (document.activeElement)
            const at = element ? inputs.get(element) : null

            if (!element || !at || !isTextInput(element)) return

            const text = sequence(at.key, at.path)

            saved = {
                element, ...at,
                anchor: text?.idBefore(toIndex(element.value, element.selectionStart ?? 0)) ?? null,
                head: text?.idBefore(toIndex(element.value, element.selectionEnd ?? 0)) ?? null,
            }
        },
        render() {
            const items = keyed()

            for (const [element, { key, path }] of inputs) write(element, items[key]?.[path])

            if (saved && document.activeElement === saved.element) {
                const text = sequence(saved.key, saved.path)
                const value = saved.element.value
                const anchor = toOffset(value, text?.indexAfter(saved.anchor) ?? 0)
                const head = toOffset(value, text?.indexAfter(saved.head) ?? 0)

                saved.element.setSelectionRange(Math.min(anchor, head), Math.max(anchor, head))
            }

            saved = null
            onValueChange?.(toList(items))
        },
        reveal: () => inputs.keys().next().value?.scrollIntoView({ block: 'center', behavior: 'smooth' }),
    })

    return {
        /**
         * Binds one item's sub-field input. Returns what unbinds it.
         *
         * @param {string} key
         * @param {string} path
         * @param {HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement} element
         */
        attach(key, path, element) {
            const events = new AbortController()
            const { signal } = events

            inputs.set(element, { key, path })

            const typed = () => {
                const items = keyed()
                const value = element instanceof HTMLInputElement && element.type === 'checkbox' ? element.checked : element.value
                const caret = isTextInput(element) ? toIndex(element.value, /** @type {HTMLInputElement} */ (element).selectionEnd ?? 0) : 0

                room.change(field, { ...items, [key]: { ...(items[key] ?? {}), [path]: value } }, isTextInput(element) ? { key, path, index: caret } : null)
                onValueChange?.(toList(keyed()))
            }

            element.addEventListener(isTextInput(element) ? 'input' : 'change', typed, { signal })
            element.addEventListener('focus', () => room.focus(field), { signal })
            element.addEventListener('blur', () => room.blur(field), { signal })

            if (room.isReady) write(element, keyed()[key]?.[path])

            return () => {
                events.abort()
                inputs.delete(element)
            }
        },

        /**
         * Shares a new list: an item added, removed or moved.
         *
         * @param {import('../core/items.js').Item[]} list
         */
        change(list) {
            room.change(field, toKeyed(list))
            onValueChange?.(toList(keyed()))
        },

        destroy() {
            inputs.clear()
            unbind()
        },
    }
}

/**
 * @param {HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement} element
 * @param {any} value
 */
function write(element, value) {
    if (element instanceof HTMLInputElement && element.type === 'checkbox') element.checked = value === true || value === 1 || value === '1'
    else if (element.value !== String(value ?? '')) element.value = value ?? ''
}
