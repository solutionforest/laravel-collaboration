import { addStyles } from './styles.js'

/**
 * The faces of everybody else in the room, for pages without a framework
 * component of their own.
 *
 * @param {Person[]} people
 * @param {{limit?: number, strings?: Record<string, string>, labels?: Record<string, string>, following?: number | null, onFollow?: ((client: number) => void) | null}} [options]
 */
export function renderAvatars(people, { limit = 5, strings = {}, labels = {}, following = null, onFollow = null } = {}) {
    addStyles()

    const list = document.createElement('div')

    list.className = 'collab-avatars'

    for (const person of people.slice(0, limit)) {
        const face = avatar(person, describe(person, strings, labels))

        // A face to click: follow that person from field to field.
        if (onFollow) {
            face.setAttribute('role', 'button')
            face.tabIndex = 0
            face.dataset.collabFollow = ''
            face.toggleAttribute('data-following', following === person.client)
            face.addEventListener('click', () => onFollow(person.client))
        }

        list.append(face)
    }

    if (people.length > limit) {
        const more = document.createElement('span')

        more.className = 'collab-avatar'
        more.style.background = '#6b7280'
        more.textContent = `+${people.length - limit}`
        more.title = translate(strings.more ?? ':count more', { count: String(people.length - limit) })
        list.append(more)
    }

    return list
}

/**
 * @param {Person} person
 * @param {string} title
 */
export function avatar(person, title = person.name) {
    const element = document.createElement('span')

    element.className = 'collab-avatar'
    element.title = title
    element.style.background = person.color
    element.toggleAttribute('data-idle', person.idle)

    if (person.avatar) {
        const image = document.createElement('img')

        image.src = person.avatar
        image.alt = ''
        image.referrerPolicy = 'no-referrer'
        image.addEventListener('error', () => image.replaceWith(person.initials))
        element.append(image)
    } else {
        element.textContent = person.initials
    }

    return element
}

/**
 * "Alice · In Title", for a tooltip.
 *
 * @param {Person} person
 * @param {Record<string, string>} strings
 * @param {Record<string, string>} labels
 */
export function describe(person, strings = {}, labels = {}) {
    const name = person.self ? (strings.you ?? 'You, in another tab') : person.name

    if (person.idle) return `${name} · ${strings.idle ?? 'Away'}`
    if (person.field) return `${name} · ${translate(strings.in_field ?? 'In :field', { field: labels[person.field] ?? person.field })}`

    return name
}

/**
 * @param {string} template
 * @param {Record<string, string>} replacements
 */
export const translate = (template, replacements) => template.replace(/:(\w+)/g, (match, key) => replacements[key] ?? match)
