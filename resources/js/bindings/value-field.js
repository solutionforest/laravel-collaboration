import { lockWhileEditing } from './lock.js'

/**
 * Makes a `<select>`, a checkbox, a group of checkboxes or a group of radios
 * collaborative: the latest choice wins.
 *
 * @param {import('../core/room.js').Room} room
 * @param {string} field
 * @param {HTMLInputElement | HTMLSelectElement | Array<HTMLInputElement>} target one element, or every input of a group
 * @param {{onValueChange?: (value: any) => void, onRemoteChange?: (value: any) => void}} [options] tells the framework's own state about others' choices
 * @returns {() => void}
 */
export function bindValueField(room, field, target, { onValueChange, onRemoteChange } = {}) {
    const elements = Array.isArray(target) ? target : [target]
    const notify = onValueChange ?? onRemoteChange
    const events = new AbortController()
    const { signal } = events

    const unbind = room.bind(field, {
        render() {
            const value = room.value(field)

            if (sameValue(read(elements), value)) return

            write(elements, value)
            notify?.(value)
        },
    })

    const unlocks = elements.map((element) => lockWhileEditing(room, field, element))

    for (const element of elements) {
        element.addEventListener('change', () => room.change(field, read(elements)), { signal })
        element.addEventListener('focus', () => room.focus(field), { signal })
        element.addEventListener('blur', () => room.blur(field), { signal })
    }

    if (room.isReady) write(elements, room.value(field))

    return () => {
        events.abort()
        unlocks.forEach((unlock) => unlock())
        unbind()
    }
}

/**
 * What the elements show, the way a form would submit it: the checked radio,
 * the ticked checkboxes of a group, one checkbox's state, a select's value.
 *
 * @param {Array<HTMLInputElement | HTMLSelectElement>} elements
 */
export function read(elements) {
    const [first] = elements

    if (isInput(first, 'radio')) return /** @type {HTMLInputElement | undefined} */ (elements.find((element) => /** @type {HTMLInputElement} */ (element).checked))?.value ?? null
    if (isInput(first, 'checkbox') && elements.length > 1) return elements.filter((element) => /** @type {HTMLInputElement} */ (element).checked).map((element) => element.value)
    if (isInput(first, 'checkbox')) return /** @type {HTMLInputElement} */ (first).checked
    if (first instanceof HTMLSelectElement && first.multiple) return [...first.selectedOptions].map((option) => option.value)

    return first?.value ?? null
}

/**
 * @param {Array<HTMLInputElement | HTMLSelectElement>} elements
 * @param {any} value
 */
function write(elements, value) {
    const values = (Array.isArray(value) ? value : []).map(String)

    for (const element of elements) {
        if (isInput(element, 'radio')) element.checked = element.value === String(value)
        else if (isInput(element, 'checkbox') && elements.length > 1) element.checked = values.includes(element.value)
        else if (isInput(element, 'checkbox')) element.checked = value === true || value === 1 || value === '1'
        else if (element instanceof HTMLSelectElement && element.multiple) [...element.options].forEach((option) => (option.selected = values.includes(option.value)))
        else element.value = value ?? ''
    }
}

/**
 * @param {Element | undefined} element
 * @param {string} type
 * @returns {element is HTMLInputElement}
 */
const isInput = (element, type) => element instanceof HTMLInputElement && element.type === type

/** @type {(a: any, b: any) => boolean} */
const sameValue = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
