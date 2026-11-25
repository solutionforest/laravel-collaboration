/**
 * Makes a `<select>`, a checkbox or a group of radios collaborative: the
 * latest choice wins.
 *
 * @param {import('../core/room.js').Room} room
 * @param {string} field
 * @param {HTMLInputElement | HTMLSelectElement | Array<HTMLInputElement>} target one element, or every radio of a group
 * @param {{onRemoteChange?: (value: any) => void}} [options]
 * @returns {() => void}
 */
export function bindValueField(room, field, target, { onRemoteChange } = {}) {
    const elements = Array.isArray(target) ? target : [target]
    const events = new AbortController()
    const { signal } = events

    const unbind = room.bind(field, {
        render() {
            const value = room.value(field)

            if (sameValue(read(elements), value)) return

            write(elements, value)
            onRemoteChange?.(value)
        },
    })

    for (const element of elements) {
        element.addEventListener('change', () => room.change(field, read(elements)), { signal })
        element.addEventListener('focus', () => room.focus(field), { signal })
        element.addEventListener('blur', () => room.blur(field), { signal })
    }

    if (room.isReady) write(elements, room.value(field))

    return () => {
        events.abort()
        unbind()
    }
}

/**
 * What the elements show, the way a form would submit it.
 *
 * @param {Array<HTMLInputElement | HTMLSelectElement>} elements
 */
export function read(elements) {
    const [first] = elements

    if (first instanceof HTMLInputElement && first.type === 'radio') return elements.find((element) => /** @type {HTMLInputElement} */ (element).checked)?.value ?? null
    if (first instanceof HTMLInputElement && first.type === 'checkbox') return first.checked
    if (first instanceof HTMLSelectElement && first.multiple) return [...first.selectedOptions].map((option) => option.value)

    return first?.value ?? null
}

/**
 * @param {Array<HTMLInputElement | HTMLSelectElement>} elements
 * @param {any} value
 */
function write(elements, value) {
    for (const element of elements) {
        if (element instanceof HTMLInputElement && element.type === 'radio') element.checked = element.value === String(value)
        else if (element instanceof HTMLInputElement && element.type === 'checkbox') element.checked = value === true || value === 1 || value === '1'
        else if (element instanceof HTMLSelectElement && element.multiple) [...element.options].forEach((option) => (option.selected = (value ?? []).map(String).includes(option.value)))
        else element.value = value ?? ''
    }
}

/** @type {(a: any, b: any) => boolean} */
const sameValue = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
