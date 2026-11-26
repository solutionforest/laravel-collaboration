import { bindTextField } from '../bindings/text-field.js'
import { bindValueField } from '../bindings/value-field.js'
import { isTextInput } from '../bindings/lock.js'
import { bindItems } from '../bindings/items.js'
import { listOps, toList } from '../core/items.js'
import { RecordPresence } from '../core/record-presence.js'
import { Room } from '../core/room.js'
import { describe, renderAvatars, translate } from '../ui/avatars.js'
import { peopleIn } from '../ui/people.js'

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

    /** @type {Map<string, {binding: ReturnType<typeof bindItems>, inputs: Map<Element, () => void>}>} each repeater, and its attached inputs */
    repeaters = new Map()
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
        this.room.on('following', () => this.render())
        this.room.on('status', () => (this.render(), this.publish()))
        this.room.on('change', () => this.publish())
        this.room.on('expired', () => this.wire.$refresh())
        this.room.on('saved', ({ name }) => {
            this.notice(translate(config.strings.saved ?? ':name saved', { name: name ?? '' }))

            // Their values, into the fields this person left alone.
            this.wire.call('pullCollaboration')
        })

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
            if (kind === 'items') {
                this.bindItems(field)

                continue
            }

            const elements = this.inputsFor(field)
            const known = this.bound.get(field)

            if (known && known.elements.length === elements.length && known.elements.every((element, index) => element === elements[index] && element.isConnected)) continue

            known?.unbind()
            this.bound.delete(field)

            if (!elements.length || kind === 'rich') continue

            const path = this.statePath + field
            const onValueChange = (/** @type {any} */ value) => this.wire.$set(path, value, false)
            const [first] = elements

            // Text with a caret merges by character; dates, numbers, choices and groups by latest value.
            const unbind = kind === 'text' && elements.length === 1 && isTextInput(first)
                ? bindTextField(this.room, field, /** @type {HTMLInputElement} */ (first), { onValueChange })
                : bindValueField(this.room, field, /** @type {any} */ (elements.length > 1 ? elements : first), { onValueChange })

            this.bound.set(field, { elements, unbind })
        }

        this.publish()
    }

    /**
     * A repeater's inputs: `wire:model="lines.0.title"`, bound to the item at that place.
     *
     * @param {string} field
     */
    bindItems(field) {
        const path = this.statePath + field
        const repeater = this.repeaters.get(field) ?? {
            binding: bindItems(this.room, field, {
                // A new or removed item needs its row rendered; an edited one only the state.
                onValueChange: (list) => this.wire.$set(path, list, toList(this.wire.get(path) ?? []).map((item) => item.key).join() !== list.map((item) => item.key).join()),
            }),
            inputs: new Map(),
        }

        this.repeaters.set(field, repeater)

        const rows = /** @type {Array<{key?: string}>} */ (this.wire.get(path) ?? [])
        const pattern = new RegExp(`^${path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\.(\\d+)\\.(.+)$`)
        const present = new Set()

        for (const element of this.component.querySelectorAll('input, textarea, select')) {
            const model = [...element.attributes].find((attribute) => attribute.name.startsWith('wire:model'))?.value ?? ''
            const [, index, sub] = model.match(pattern) ?? []
            const key = index === undefined ? undefined : rows[Number(index)]?.key

            if (!key || !sub || element.closest('[wire\\:id]') !== this.component) continue

            present.add(element)

            if (!repeater.inputs.has(element)) repeater.inputs.set(element, repeater.binding.attach(key, sub, /** @type {HTMLInputElement} */ (element)))
        }

        for (const [element, detach] of repeater.inputs) {
            if (present.has(element)) continue

            detach()
            repeater.inputs.delete(element)
        }
    }

    /**
     * Adds, removes or moves a repeater's items for everybody:
     * `x-on:click="Collaboration.items($el, 'lines').add({ title: '' })"`.
     *
     * @param {string} field
     */
    items(field) {
        const repeater = this.repeaters.get(field)
        const list = () => toList(/** @type {any} */ (this.room.value(field)))

        return {
            add: (/** @type {Record<string, any>} */ item = {}) => repeater?.binding.change(listOps.add(list(), item)),
            remove: (/** @type {string} */ key) => repeater?.binding.change(listOps.remove(list(), key)),
            move: (/** @type {string} */ key, /** @type {number} */ to) => repeater?.binding.change(listOps.move(list(), key, to)),
        }
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

        presence?.replaceChildren(renderAvatars(this.room.people, { limit: avatarLimit, strings, labels, following: this.room.following, onFollow: (client) => this.room.follow(client) }))
        presence?.setAttribute('title', this.room.people.map((person) => describe(person, strings, labels)).join('\n') || (strings.alone ?? ''))

        if (status && !this.noticeTimer) status.textContent = strings[this.room.status] ?? ''

        // `<x-collaboration::field-people>`: who is in each field.
        for (const spot of this.component.querySelectorAll(/** @type {'span'} */ ('[data-collab-people]'))) {
            spot.replaceChildren(...peopleIn(this.room.people, spot.dataset.collabPeople ?? '').map((person) => {
                const label = Object.assign(document.createElement('span'), { className: 'collab-field-person', textContent: person.name })

                label.style.background = person.color

                return label
            }))
        }

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
        this.repeaters.forEach(({ binding }) => binding.destroy())
        this.room.destroy()
    }
}

function scan() {
    if (!window.Livewire) return

    const present = new Set()

    for (const el of document.querySelectorAll(/** @type {'div'} */ ('[data-collaboration]'))) {
        const source = el.dataset.collaboration ?? ''

        const component = el.closest('[wire\\:id]')

        // Livewire starts after this script; a room needs its component.
        if (!source || !component || !window.Livewire.find(component.getAttribute('wire:id'))) continue

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
    let attempts = 0

    const attempt = () => {
        const room = roomFor(element)

        if (room?.isReady) return callback(room)
        if (room) return room.on('ready', () => callback(room))

        // Livewire may still be starting; a page without a room stops asking after a minute.
        if (++attempts < 600) setTimeout(attempt, 100)
    }

    attempt()
}

const queued = window.Collaboration?.queue ?? []

/**
 * A Livewire repeater's buttons: `Collaboration.items($el, 'lines').add({ title: '' })`.
 *
 * @param {Element} element
 * @param {string} field
 */
function items(element, field) {
    const component = element.closest('[wire\\:id]')
    const entry = [...rooms.values()].find((item) => item.component === component)

    return entry?.items(field) ?? { add() {}, remove() {}, move() {} }
}

window.Collaboration = { rooms, roomFor, whenReady, items, Room }

// Calls made before this script loaded, kept by the stub `<x-collaboration::room>` renders.
queued.forEach(([element, callback]) => whenReady(element, callback))

/** @type {Map<HTMLElement, RecordPresence>} lists showing who is on each record */
const lists = new Map()

/** Fills each `<x-collaboration::record-people>` of each `<x-collaboration::record-presence>`. */
function scanLists() {
    for (const el of document.querySelectorAll(/** @type {'div'} */ ('[data-collab-records]'))) {
        if (lists.has(el)) continue

        const presence = new RecordPresence(JSON.parse(el.dataset.collabRecords ?? '{}'))
        const render = () => el.querySelectorAll(/** @type {'span'} */ ('[data-collab-record]')).forEach((spot) => {
            spot.replaceChildren(renderAvatars(presence.peopleOn(spot.dataset.collabRecord ?? ''), { limit: 3 }))
        })

        presence.on('people', render)
        lists.set(el, presence)
        render()
    }

    for (const [el, presence] of lists) {
        if (el.isConnected) continue

        presence.destroy()
        lists.delete(el)
    }
}

document.addEventListener('livewire:initialized', scanLists)
setInterval(scanLists, 1000)
document.addEventListener('livewire:initialized', scan)
document.addEventListener('livewire:navigated', scan)
setInterval(scan, 300)
scan()
