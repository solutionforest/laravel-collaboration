import { toOffset } from '../crdt/id.js'
import { addStyles } from './styles.js'
import { measureText } from './measure-text.js'

/** Draws other people's carets and selections over one input or textarea. */
export class CaretLayer {
    /**
     * @param {import('../core/room.js').Room} room
     * @param {string} field
     * @param {HTMLInputElement | HTMLTextAreaElement} element
     */
    constructor(room, field, element) {
        this.room = room
        this.field = field
        this.element = element
        /** @type {HTMLDivElement | null} */
        this.layer = null
        this.frame = 0
        this.onScroll = () => this.draw()

        window.addEventListener('scroll', this.onScroll, { capture: true, passive: true })
        window.addEventListener('resize', this.onScroll, { passive: true })
    }

    draw() {
        cancelAnimationFrame(this.frame)
        this.frame = requestAnimationFrame(() => this.paint())
    }

    paint() {
        const carets = this.element.isConnected ? this.room.carets(this.field) : []

        this.element.toggleAttribute('data-collab-busy', carets.length > 0)
        this.element.style.setProperty('--collab-color', carets[0]?.person.color ?? 'transparent')

        if (!carets.length) {
            this.layer?.replaceChildren()

            return
        }

        addStyles()
        this.layer ??= document.body.appendChild(Object.assign(document.createElement('div'), { className: 'collab-carets' }))

        const value = this.element.value

        this.layer.replaceChildren(...carets.flatMap(({ person, anchor, head }) => {
            const geometry = measureText(this.element, toOffset(value, anchor), toOffset(value, head))

            if (!geometry || !isInside(geometry.caret, geometry.clip)) return []

            const caret = box('collab-caret', geometry.caret, person.color)
            const label = Object.assign(document.createElement('span'), { className: 'collab-caret-label', textContent: person.name })

            label.style.background = person.color
            caret.append(label)

            return [...geometry.selection.map((rect) => box('collab-selection', clipped(rect, geometry.clip), person.color)), caret]
        }))
    }

    destroy() {
        cancelAnimationFrame(this.frame)
        window.removeEventListener('scroll', this.onScroll, { capture: true })
        window.removeEventListener('resize', this.onScroll)
        this.element.removeAttribute('data-collab-busy')
        this.layer?.remove()
    }
}

/**
 * @param {string} className
 * @param {DOMRect} rect
 * @param {string} color
 */
function box(className, rect, color) {
    const element = document.createElement('div')

    element.className = className
    Object.assign(element.style, { left: `${rect.left}px`, top: `${rect.top}px`, width: `${Math.max(2, rect.width)}px`, height: `${rect.height}px`, background: color })

    return element
}

/** @type {(rect: DOMRect, clip: DOMRect) => boolean} */
const isInside = (rect, clip) => rect.left >= clip.left - 1 && rect.left <= clip.right + 1 && rect.top >= clip.top - 1 && rect.bottom <= clip.bottom + 1

/** @type {(rect: DOMRect, clip: DOMRect) => DOMRect} */
function clipped(rect, clip) {
    const left = Math.max(rect.left, clip.left)
    const right = Math.min(rect.right, clip.right)

    return new DOMRect(left, rect.top, Math.max(0, right - left), rect.height)
}
