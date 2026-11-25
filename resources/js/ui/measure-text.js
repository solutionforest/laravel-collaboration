/** The styles that decide where text is laid out. */
const COPIED = [
    'box-sizing', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width', 'padding-top',
    'padding-right', 'padding-bottom', 'padding-left', 'font-family', 'font-size', 'font-style', 'font-weight',
    'font-variant', 'font-feature-settings', 'font-variation-settings', 'line-height', 'letter-spacing', 'word-spacing',
    'text-align', 'text-indent', 'text-transform', 'tab-size', 'direction',
]

/** @type {HTMLDivElement | null} */
let mirror = null

/**
 * Inputs do not report where a character is, so lay the same text out in a
 * hidden copy and measure that.
 *
 * @param {HTMLInputElement | HTMLTextAreaElement} input
 * @param {number} anchor a UTF-16 offset
 * @param {number} head a UTF-16 offset
 * @returns {Geometry | null}
 */
export function measureText(input, anchor, head) {
    const bounds = input.getBoundingClientRect()

    if (!bounds.width) return null

    if (!mirror?.isConnected) {
        mirror = document.createElement('div')
        mirror.setAttribute('aria-hidden', 'true')
        mirror.setAttribute('data-collaboration-mirror', '')
        document.body.append(mirror)
    }

    const computed = getComputedStyle(input)
    const multiline = input.tagName === 'TEXTAREA'
    const { style } = mirror

    // A transition would report the previous position until the next frame.
    style.setProperty('transition', 'none', 'important')
    style.setProperty('animation', 'none', 'important')
    style.position = 'fixed'
    style.visibility = 'hidden'
    style.pointerEvents = 'none'
    style.margin = '0'
    style.borderStyle = 'solid'

    for (const property of COPIED) style.setProperty(property, computed.getPropertyValue(property))

    const inner =
        input.clientHeight - parseFloat(computed.paddingTop) - parseFloat(computed.paddingBottom)

    if (!multiline) style.lineHeight = `${inner}px`

    const scrollbar = multiline
        ? Math.max(0, input.offsetWidth - input.clientWidth - parseFloat(computed.borderLeftWidth) - parseFloat(computed.borderRightWidth))
        : 0

    style.whiteSpace = multiline ? 'pre-wrap' : 'pre'
    style.overflowWrap = multiline ? 'break-word' : 'normal'
    style.left = `${bounds.left - input.scrollLeft}px`
    style.top = `${bounds.top - input.scrollTop}px`
    style.width = multiline ? `${bounds.width - scrollbar}px` : 'max-content'
    style.minWidth = `${bounds.width - scrollbar}px`

    // The zero-width space gives an empty last line a box to measure.
    mirror.textContent = `${input.value}\u200b`

    const text = /** @type {Text} */ (mirror.firstChild)
    const point = document.createRange()

    point.setStart(text, Math.min(head, text.length - 1))
    point.collapse(true)

    const box = point.getClientRects()[0] ?? point.getBoundingClientRect()
    /** @type {DOMRect[]} */
    let selection = []

    if (anchor !== head) {
        const range = document.createRange()

        range.setStart(text, Math.min(anchor, head, text.length - 1))
        range.setEnd(text, Math.min(Math.max(anchor, head), text.length - 1))
        selection = [...range.getClientRects()]
    }

    const caret = new DOMRect(box.left, box.top, 2, box.height)

    mirror.textContent = ''

    return { caret, selection, clip: bounds }
}
