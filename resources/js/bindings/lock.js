import { translate } from '../ui/avatars.js'

/**
 * A field the room locks (`->lock('status')`) is read-only for everybody
 * else while somebody is in it, and says who that is.
 *
 * @param {import('../core/room.js').Room} room
 * @param {string} field
 * @param {HTMLElement} element
 * @returns {() => void} what unlocks it for good
 */
export function lockWhileEditing(room, field, element) {
    if (!room.config.locks?.includes(field)) return () => {}

    const apply = () => {
        const editor = room.lockedBy(field)
        const isMine = document.activeElement === element

        element.inert = editor !== null && !isMine
        element.toggleAttribute('data-collab-locked', element.inert)
        element.title = element.inert ? translate(room.config.strings.locked_field ?? ':name is editing this field', { name: editor?.name ?? '' }) : ''
    }

    const stop = room.on('people', apply)

    apply()

    return () => {
        stop()
        element.inert = false
        element.removeAttribute('data-collab-locked')
        element.title = ''
    }
}

/**
 * Whether an input edits text with a caret. Others, such as a date, a number
 * or an email, are shared as a whole value when they change.
 *
 * @param {Element | null} element
 */
export function isTextInput(element) {
    if (element instanceof HTMLTextAreaElement) return true

    return element instanceof HTMLInputElement && ['text', 'search', 'url', 'tel', 'password', ''].includes(element.type)
}
