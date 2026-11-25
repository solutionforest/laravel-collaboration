import { bindTextField } from '../bindings/text-field.js'
import { bindValueField } from '../bindings/value-field.js'
import { Room } from '../core/room.js'
import { describe, renderAvatars, translate } from '../ui/avatars.js'

/**
 * The Livewire adapter. `<x-collaboration::room>` renders an element with the
 * room's config; this finds it, joins the room, and makes the component's
 * `wire:model` inputs collaborative. Nothing to set up in JavaScript.
 */

/** @type {Map<HTMLElement, LivewireRoom>} */
const rooms = new Map()

class LivewireRoom {
    /** @type {Map<string, {elements: Element[], unbind: () => void}>} */
    bound = new Map()
    published = ''

    /**
     * @param {HTMLElement} el
     * @param {RoomConfig & {statePath?: string}} config
     */
    constructor(el, config) {
        this.el = el
        this.component = /** @type {HTMLElement} */ (el.closest('[wire\\:id]'))
        this.wire = window.Livewire.find(this.component.getAttribute('wire:id'))
        this.room = new Room(config)
        this.statePath = config.statePath ? `${config.statePath}.` : ''

        this.room.on('ready', () => this.bind())
        this.room.on('people', () => this.render())
        this.room.on('status', () => (this.render(), this.publish()))
        this.room.on('change', () => this.publish())
        this.room.on('expired', () => this.wire.$refresh())
        this.room.on('saved', ({ name }) => this.notice(translate(config.strings.saved ?? ':name saved', { name: name ?? '' })))

        this.events = new AbortController()

        // Capture runs before Livewire, so a save always carries the latest edit.
        for (const name of ['click', 'submit', 'keydown']) {
            document.addEventListener(name, (event) => {
                if (event.target instanceof Node && this.component.contains(event.target)) this.publish()
            }, { capture: true, signal: this.events.signal })
        }

        this.render()
    }

    /** @param {RoomConfig} config */
    update(config) {
        this.room.renew(config)
    }

    /** Binds every live field's inputs; called again after each morph. */
    bind() {
        if (!this.room.isReady) return

        for (const [field, kind] of Object.entries(this.room.config.fields)) {
            const elements = this.inputsFor(field)
            const known = this.bound.get(field)

            if (known && known.elements.length === elements.length && known.elements.every((element, index) => element === elements[index] && element.isConnected)) continue

            known?.unbind()
            this.bound.delete(field)

            if (!elements.length || kind === 'rich') continue

            const path = this.statePath + field
            const onRemoteChange = (/** @type {any} */ value) => this.wire.$set(path, value, false)
            const [first] = elements
            const unbind = kind === 'text' && (first instanceof HTMLInputElement || first instanceof HTMLTextAreaElement)
                ? bindTextField(this.room, field, first, { onRemoteChange })
                : bindValueField(this.room, field, /** @type {any} */ (elements.length > 1 ? elements : first), { onRemoteChange })

            this.bound.set(field, { elements, unbind })
        }

        this.publish()
    }

    /**
     * The component's inputs for a field: `wire:model="title"`, with any
     * modifier, or `data-collaborate="title"`.
     *
     * @param {string} field
     * @returns {Element[]}
     */
    inputsFor(field) {
        const path = this.statePath + field

        return [...this.component.querySelectorAll('input, textarea, select')].filter((element) => {
            if (element.closest('[wire\\:id]') !== this.component) return false
            if (element.getAttribute('data-collaborate') === field) return true

            return [...element.attributes].some((attribute) => attribute.name.startsWith('wire:model') && attribute.value === path)
        })
    }

    /** Tells the component what a save needs, without a request. */
    publish() {
        const payload = this.room.payload()

        if (payload === this.published) return

        this.published = payload
        this.wire.$set('collaborationSync', payload, false)
    }

    render() {
        const presence = this.el.querySelector('[data-collab-presence]')
        const status = this.el.querySelector('[data-collab-status]')
        const { strings, labels, avatarLimit } = this.room.config

        presence?.replaceChildren(renderAvatars(this.room.people, { limit: avatarLimit, strings, labels }))
        presence?.setAttribute('title', this.room.people.map((person) => describe(person, strings, labels)).join('\n') || (strings.alone ?? ''))

        if (status && !this.noticeTimer) status.textContent = strings[this.room.status] ?? ''

        this.el.dataset.status = this.room.status
    }

    /** @param {string} text */
    notice(text) {
        const status = this.el.querySelector('[data-collab-status]')

        if (!status) return

        clearTimeout(this.noticeTimer)
        status.textContent = text
        this.noticeTimer = setTimeout(() => {
            this.noticeTimer = undefined
            this.render()
        }, 4000)
    }

    destroy() {
        this.events.abort()
        this.bound.forEach(({ unbind }) => unbind())
        this.room.destroy()
    }
}

function scan() {
    if (!window.Livewire) return

    const present = new Set()

    for (const el of document.querySelectorAll(/** @type {'div'} */ ('[data-collaboration]'))) {
        const source = el.dataset.collaboration ?? ''

        if (!source || !el.closest('[wire\\:id]')) continue

        const config = JSON.parse(source)
        const known = rooms.get(el) ?? [...rooms.values()].find((entry) => !entry.el.isConnected && entry.room.config.draft === config.draft)

        present.add(el)

        if (known) {
            if (known.el !== el) {
                rooms.delete(known.el)
                known.el = el
                rooms.set(el, known)
            }

            if (known.room.config.token !== config.token) known.update(config)

            known.bind()
            known.render()

            continue
        }

        rooms.set(el, new LivewireRoom(el, config))
    }

    for (const [el, entry] of rooms) {
        if (present.has(el)) continue

        entry.destroy()
        rooms.delete(el)
    }
}

/**
 * The room a page element is in, for adapters such as a TipTap editor:
 *
 *     Collaboration.whenReady(this.$el, (room) => ...)
 *
 * @param {Element} element
 */
function roomFor(element) {
    const component = element.closest('[wire\\:id]')

    return [...rooms.values()].find((entry) => entry.component === component)?.room ?? null
}

/**
 * @param {Element} element
 * @param {(room: Room) => void} callback
 */
function whenReady(element, callback) {
    const attempt = () => {
        const room = roomFor(element)

        if (room?.isReady) return callback(room)
        if (room) return room.on('ready', () => callback(room))

        setTimeout(attempt, 100)
    }

    attempt()
}

window.Collaboration = { ...(window.Collaboration ?? {}), rooms, roomFor, whenReady, Room }

document.addEventListener('livewire:initialized', scan)
document.addEventListener('livewire:navigated', scan)
setInterval(scan, 300)
scan()
