import { Document } from '../crdt/document.js'
import { Outbox } from './outbox.js'
import { Presence } from './presence.js'
import { Realtime } from './realtime.js'
import { Recovery } from './recovery.js'
import { HttpTransport } from './transport.js'

/** Without a key press or a click for this long, a person shows as away. */
const IDLE_AFTER = 120_000

/** @type {(milliseconds: number) => Promise<void>} */
const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds))

/**
 * What an adapter tells the room about one field it shows on the page.
 *
 * @typedef {object} FieldBinding
 * @property {() => void} [capture] called before others' changes are applied: remember the caret
 * @property {() => void} [render] called after: show the new value and put the caret back
 * @property {() => ({anchor: number, head: number} | null)} [cursor] this person's selection, while they are in the field
 * @property {boolean} [isBusy] true during an IME composition, which must not be replaced
 * @property {() => void} [reveal] brings the field into view, for somebody following a colleague
 */

/**
 * One record's room, in this tab: the shared draft, the people in it, and
 * the loop that keeps both up to date. Framework adapters build on this.
 *
 *     const room = new Room(config)
 *
 *     room.on('change', ({ field, value }) => form[field] = value)
 *     room.change('title', 'Hello world')
 */
export class Room {
    /** @type {Document | null} */
    document = null
    status = 'connecting'
    epoch = 0
    sequence = -1
    /** @type {string | null} the field this person is in */
    field = null
    /** @type {{version: number, name: string | null} | null} */
    saved = null
    /** @type {string | null} */
    revision = null
    syncing = false
    stopped = false
    destroyed = false
    failures = 0
    lastActive = Date.now()
    /** @type {ReturnType<typeof setTimeout> | null} */
    timer = null
    /** @type {Set<string> | undefined} fields already warned about */
    warned
    /** @type {number | null} the client of the colleague this page follows from field to field */
    following = null
    /** @type {string | null} the field they were last seen in */
    followedField = null
    /** when they were last seen in the room */
    followedSeenAt = 0
    dueAt = 0

    /**
     * @param {RoomConfig} config what `Collaboration::room()` rendered
     * @param {{transport?: {send: HttpTransport['send']}}} [options]
     */
    constructor(config, { transport = new HttpTransport() } = {}) {
        this.config = config
        this.transport = transport
        this.outbox = new Outbox()
        this.presence = new Presence()
        this.recovery = new Recovery(typeof sessionStorage === 'undefined' ? null : `${config.draft}:${config.user}`)
        this.realtime = new Realtime(this)
        /** @type {Map<string, FieldBinding>} */
        this.bindings = new Map()
        /** @type {Map<string, Set<(detail: any) => void>>} */
        this.listeners = new Map()

        this.listen()
        this.syncSoon(0)
    }

    get isReady() {
        return this.document !== null
    }

    get isLive() {
        return Object.keys(this.config.fields).length > 0
    }

    /** Everybody else here, one entry per person. */
    get people() {
        return this.presence.users()
    }

    /** @param {string} field */
    isLiveField(field) {
        return !this.stopped && !!this.document && this.document.kind(field) === this.config.fields[field]
    }

    /**
     * The shared value of a field: text, a rich text document, or a picked value.
     *
     * @param {string} field
     */
    value(field) {
        return this.document?.value(field) ?? null
    }

    /** @param {string} field */
    label(field) {
        return this.config.labels[field] ?? field
    }

    /**
     * Records this person's edit and shares it.
     *
     * @param {string} field
     * @param {any} value the field's whole new value
     * @param {number | null} [caret] for text, where the caret is after the edit, so typing a repeated letter merges right
     */
    change(field, value, caret = null) {
        if (!this.isLiveField(field) || !this.document) return

        const operations = this.document.changes(field, value, this.config.client, caret)

        if (!operations.length) return

        this.document.apply(operations)
        this.outbox.push(operations)
        this.field = field
        this.active()
        this.setStatus('syncing')
        this.remember()
        this.syncSoon(this.realtime.isConnected ? 30 : Math.max(100, this.pollingInterval() / 3))
    }

    /** @param {string | null} field the field this person is now in, or null */
    focus(field) {
        if (field === this.field) return

        this.field = field
        this.cursorMoved()
    }

    /** @param {string} field */
    blur(field) {
        if (this.field === field) this.focus(null)
    }

    cursorMoved() {
        this.active()
        this.syncSoon(this.realtime.isConnected ? 60 : Math.max(150, this.pollingInterval() / 2))
    }

    /**
     * Shows a field on the page. Returns what stops showing it.
     *
     * @param {string} field
     * @param {FieldBinding} binding
     * @returns {() => void}
     */
    bind(field, binding) {
        this.warnAboutUnknown(field)
        this.bindings.set(field, binding)
        this.remember()

        return () => {
            if (this.bindings.get(field) !== binding) return

            this.bindings.delete(field)
            this.remember()
        }
    }

    /**
     * The id of the character before a position, which stays put as others type.
     *
     * @param {string} field
     * @param {number} index
     */
    idAt(field, index) {
        return this.document?.sequence(field)?.idBefore(index) ?? null
    }

    /**
     * Where a character is now: the position right after it.
     *
     * @param {string} field
     * @param {Id | null} id
     */
    indexOf(field, id) {
        return this.document?.sequence(field)?.indexAfter(id) ?? null
    }

    /**
     * Other people's selections in a field, as positions.
     *
     * @param {string} field
     * @returns {Array<{person: Person, anchor: number, head: number}>}
     */
    carets(field) {
        return this.presence.people.flatMap((person) => {
            if (person.field !== field || !person.cursor || person.idle) return []

            const anchor = this.indexOf(field, person.cursor.anchor)
            const head = this.indexOf(field, person.cursor.head)

            return anchor === null || head === null ? [] : [{ person, anchor, head }]
        })
    }

    /** @param {string} field */
    peopleIn(field) {
        return this.people.filter((person) => person.field === field && !person.idle)
    }

    /**
     * Who holds a locked field (`->lock()` in PHP) right now, if anybody else does.
     *
     * @param {string} field
     * @returns {Person | null}
     */
    lockedBy(field) {
        if (!this.config.locks?.includes(field) || this.stopped) return null

        return this.people.find((person) => person.field === field && !person.idle && !person.self && person.mode === 'editing') ?? null
    }

    /**
     * A field the room does not have is most often a typo: say so, once.
     *
     * @param {string} field
     */
    warnAboutUnknown(field) {
        const known = [...new Set([...Object.keys(this.config.fields), ...Object.keys(this.config.labels ?? {})])]

        if (known.includes(field) || this.warned?.has(field)) return

        this.warned = (this.warned ?? new Set()).add(field)
        console.warn(`[collaboration] "${field}" is not a field of this room, so it is not shared. Its fields are: ${known.join(', ')}.`)
    }

    /**
     * Follow a colleague: the page brings whichever field they are in into
     * view, until they leave or this person does something. Null stops.
     *
     * @param {number | null} client
     */
    follow(client) {
        this.following = client === this.following ? null : client
        this.followedField = null
        this.followedSeenAt = Date.now()
        this.emit('following', this.following)
        this.followUp()
    }

    /** Goes where the person followed is now. */
    followUp() {
        if (this.following === null) return

        const person = this.presence.people.find((item) => item.client === this.following)

        // Gone for a while: a closed tab, or no longer allowed in.
        if (!person) {
            if (Date.now() - this.followedSeenAt > 10_000) this.follow(null)

            return
        }

        this.followedSeenAt = Date.now()

        if (!person.field || person.field === this.followedField) return

        this.followedField = person.field
        this.bindings.get(person.field)?.reveal?.()
    }

    /**
     * Asks the server something about this room, such as its comments:
     * `room.call('comments')`. The room's token goes with it.
     *
     * @param {string} endpoint a name from `config.endpoints`
     * @param {Record<string, unknown>} [payload]
     * @returns {Promise<any>}
     */
    call(endpoint, payload = {}) {
        const url = this.config.endpoints?.[endpoint]

        if (!url) return Promise.reject(new Error(`[collaboration] This room has no "${endpoint}" endpoint.`))

        return this.transport.send({ url, csrf: this.config.csrf }, { ...payload, token: this.config.token })
    }

    /**
     * Stores an image for a rich text field, for everybody to see at once.
     *
     * @param {File} file
     * @param {string} field
     * @returns {Promise<string>} its URL
     */
    async upload(file, field) {
        const url = this.config.endpoints?.uploads

        if (!url) throw new Error('[collaboration] This room takes no uploads.')

        const body = new FormData()

        body.append('token', this.config.token)
        body.append('field', field)
        body.append('file', file)

        const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf } })

        if (!response.ok) throw new Error(`[collaboration] The image was not stored (${response.status}).`)

        return (await response.json()).url
    }

    /**
     * What a form sends when it is saved, so the save includes edits the
     * server has not seen yet. `Room::save()` reads it.
     */
    payload() {
        if (this.stopped || !this.document) return ''

        return JSON.stringify({ token: this.config.token, epoch: this.epoch, fields: [...this.bindings.keys()].filter((field) => this.isLiveField(field)), batches: this.outbox.batches })
    }

    /**
     * Listen for `ready`, `change`, `people`, `status`, `saved`, `expired` or `deleted`.
     *
     * @param {string} event
     * @param {(detail: any) => void} callback
     * @returns {() => void} what stops listening
     */
    on(event, callback) {
        const listeners = this.listeners.get(event) ?? new Set()

        listeners.add(callback)
        this.listeners.set(event, listeners)

        return () => listeners.delete(callback)
    }

    /**
     * @param {string} event
     * @param {any} [detail]
     */
    emit(event, detail) {
        this.listeners.get(event)?.forEach((callback) => callback(detail))
    }

    /**
     * Carries on with a freshly rendered config, after the token expired.
     *
     * @param {RoomConfig} config
     */
    renew(config) {
        if (!config?.token || config.draft !== this.config.draft) return

        this.config = config

        if (this.status === 'expired') this.setStatus(this.outbox.isEmpty ? 'synced' : 'syncing')

        this.syncSoon(0)
    }

    destroy() {
        if (this.destroyed) return

        this.leave()
        this.destroyed = true
        clearTimeout(this.timer ?? undefined)
        this.events?.abort()
        this.realtime.destroy()
        this.listeners.clear()
        this.bindings.clear()
    }

    listen() {
        if (typeof window === 'undefined') return

        this.events = new AbortController()

        const { signal } = this.events

        for (const name of ['keydown', 'pointerdown']) {
            window.addEventListener(name, (event) => {
                this.active()

                // Doing something yourself stops following somebody else.
                if (this.following !== null && event.isTrusted && !(event.target instanceof Element && event.target.closest('[data-collab-follow]'))) this.follow(null)
            }, { signal, capture: true, passive: true })
        }

        document.addEventListener(
            'visibilitychange',
            () => {
                if (document.hidden) return

                this.active()
                this.syncSoon(0)
            },
            { signal },
        )

        window.addEventListener('online', () => this.syncSoon(0), { signal })
        window.addEventListener('pagehide', () => this.leave(), { signal })
    }

    active() {
        this.lastActive = Date.now()
    }

    isIdle() {
        return (typeof document !== 'undefined' && document.hidden) || Date.now() - this.lastActive > IDLE_AFTER
    }

    interval() {
        // Pushed changes arrive at once, so polling is only a heartbeat and a safety net.
        return this.realtime.isConnected ? 8000 : this.pollingInterval()
    }

    /** How often to ask for changes when nothing pushes them. */
    pollingInterval() {
        const base = this.config.interval

        if (typeof document !== 'undefined' && document.hidden) return 5000
        if (!this.isLive) return Math.max(base, 2000)

        const others = this.presence.activeCount

        // Alone there is nothing to receive, so ask less often.
        if (!others) return this.outbox.isEmpty ? Math.max(base, 1500) : base

        // A hundred editors sync every few seconds instead of multiplying the load.
        const crowd = base * Math.max(1, Math.sqrt(others / 10))

        return this.isIdle() ? Math.max(crowd, 2000) : crowd
    }

    syncSoon(delay = this.interval()) {
        if (this.destroyed || ['denied', 'deleted'].includes(this.status)) return

        const at = Date.now() + delay

        if (this.timer && this.dueAt <= at) return

        clearTimeout(this.timer ?? undefined)
        this.dueAt = at
        this.timer = setTimeout(() => {
            this.timer = null
            this.sync()
        }, delay)
    }

    async sync() {
        if (this.destroyed || this.syncing) return

        this.syncing = true

        const batches = this.stopped ? [] : this.outbox.next()
        const resume = this.stopped ? null : this.recovery.next()
        const request = {
            token: this.config.token,
            epoch: this.epoch,
            since: this.sequence,
            presence: this.presence.etag,
            field: this.field,
            cursor: this.cursor(),
            idle: this.isIdle(),
            batches,
            resume,
        }

        let delay = null

        try {
            const response = await this.transport.send(this.config, request)

            // Never replace text under an unfinished IME composition.
            while (!this.destroyed && this.isBusy()) await wait(80)

            if (this.destroyed) return

            this.failures = 0
            this.recovery.receive(resume, response.resumed)
            this.receive(response, batches.at(-1)?.n ?? 0)
            this.realtime.connect()
        } catch (error) {
            delay = this.handleFailure(error, batches)
        } finally {
            this.syncing = false

            if (delay !== null) {
                clearTimeout(this.timer ?? undefined)
                this.timer = null
            }

            if (delay !== -1) this.syncSoon(delay ?? (this.outbox.isEmpty && this.recovery.isEmpty ? this.interval() : Math.min(150, this.interval())))
        }
    }

    /**
     * @param {SyncResponse} response
     * @param {number} sent the last batch number this request carried
     */
    receive(response, sent) {
        this.outbox.acknowledge(sent)

        if (!this.stopped) {
            if (response.state) this.restart(response)
            else if (response.operations.length) this.merge(response.operations)

            this.epoch = response.epoch
            this.sequence = response.sequence
            this.remember()
        }

        if (this.presence.receive(response)) this.emit('people', this.people)

        this.followUp()

        this.noticeSave(response)
        this.setStatus(this.stopped ? 'stopped' : this.outbox.isEmpty ? 'synced' : 'syncing')
    }

    /**
     * Others' changes: remember carets, apply, show.
     *
     * @param {Operation[]} operations
     */
    merge(operations) {
        if (!this.document) return

        const fields = [...new Set(operations.map((operation) => operation.f))]

        fields.forEach((field) => this.bindings.get(field)?.capture?.())
        this.document.apply(operations)
        fields.forEach((field) => this.changed(field))
    }

    /**
     * The whole draft, sent first, and again when this tab fell too far behind.
     *
     * @param {SyncResponse} response
     */
    restart({ state, epoch, acknowledged }) {
        const isFirst = this.document === null

        if (!isFirst && epoch !== this.epoch) {
            // The draft was discarded or restored: edits made against the old one cannot apply.
            if (!this.outbox.isEmpty) return this.stop()

            this.outbox.reset()
        }

        this.outbox.acknowledge(acknowledged)
        this.bindings.forEach((binding) => binding.capture?.())
        this.document = new Document(state ?? {})

        for (const { ops } of this.outbox.batches) this.document.apply(ops)

        Object.keys(this.config.fields).forEach((field) => this.changed(field))

        if (isFirst) this.emit('ready', this)
    }

    /** @param {string} field */
    changed(field) {
        this.bindings.get(field)?.render?.()
        this.emit('change', { field, value: this.value(field) })
    }

    /** @param {SyncResponse} response */
    noticeSave({ revision, saved }) {
        if (!revision) return

        const isNewVersion = this.saved !== null && saved.version !== this.saved.version

        this.revision = revision
        this.saved = { version: saved.version, name: saved.name }

        if (isNewVersion && !saved.own) this.emit('saved', { name: saved.name })
    }

    /**
     * @param {any} error a `SyncError`, or a network error
     * @param {Batch[]} batches what the failed request carried
     * @returns {number | null} when to try again; -1 to stop trying
     */
    handleFailure(error, batches) {
        const { status, code } = error

        if (status === 410) {
            // The page renders a new token through `renew()`; until then, keep the edits.
            this.setStatus('expired')
            this.emit('expired', this)

            return 3000
        }

        if (status === 404 && code === 'deleted') {
            this.setStatus('deleted')
            this.emit('deleted', { name: error.body?.name ?? null })

            return -1
        }

        // Only our own refusal is final. A proxy's or a deploy's 403 or 404 is worth retrying.
        if ((status === 403 && code === 'forbidden') || (status === 404 && code === 'not_found')) {
            this.stop('denied')

            return -1
        }

        if ((status === 409 || status === 413) && batches.length) {
            this.stop()

            return 2000
        }

        this.setStatus('offline')

        return Math.min(15000, (status === 429 ? 3000 : 1000) * 2 ** Math.min(4, this.failures++))
    }

    /** Live editing cannot continue. The form keeps what it shows, and merges on save. */
    stop(status = 'stopped') {
        this.stopped = true
        this.outbox.reset()
        this.remember()
        this.setStatus(status)
    }

    /** @param {string} status */
    setStatus(status) {
        if (status === this.status) return

        this.status = status
        this.emit('status', status)
    }

    /** Tells the others at once that this tab left. */
    leave() {
        if (this.destroyed || !this.document) return

        this.transport.send(this.config, { token: this.config.token, leave: true, batches: this.outbox.next(), epoch: this.epoch }, { keepalive: true }).catch(() => {})
    }

    /** Keeps unsent edits through a reload. */
    remember() {
        this.recovery.store(this.config.token, this.epoch, this.outbox.batches)
    }

    isBusy() {
        return [...this.bindings.values()].some((binding) => binding.isBusy)
    }

    /** This person's selection in the field they are in, as ids that survive others typing. */
    cursor() {
        const field = this.field
        const selection = field && this.isLiveField(field) ? this.bindings.get(field)?.cursor?.() : null

        if (!field || !selection) return null

        return { anchor: this.idAt(field, selection.anchor), head: this.idAt(field, selection.head) }
    }

    /**
     * A change pushed over Laravel Echo. Operations that follow on from what
     * this tab has are applied at once; anything else is fetched.
     *
     * @param {Record<string, any>} payload
     */
    pushed(payload = {}) {
        if (this.destroyed) return

        if (payload.operations && this.document && !this.stopped) {
            const isNext = payload.epoch === this.epoch && payload.from === this.sequence && this.sequence >= 0

            if (isNext && !this.syncing && !this.isBusy()) {
                this.merge(payload.operations)
                this.sequence = payload.to
            } else if (payload.to > this.sequence || payload.epoch !== this.epoch) {
                this.syncSoon(this.isBusy() ? 80 : 30)
            }
        }

        if (payload.person) {
            this.presence.move(payload.person)
            this.emit('people', this.people)
            this.followUp()
        }

        if (payload.sync || payload.refresh) this.syncSoon(30)

        // A crowd coming and going is fetched a little less eagerly.
        if (payload.people) this.syncSoon(Math.min(1500, Math.max(50, 10 * this.presence.activeCount)))
    }

    realtimeChanged() {
        if (!this.destroyed) this.syncSoon(this.realtime.isConnected ? 0 : Math.min(this.interval(), 400))
    }
}
