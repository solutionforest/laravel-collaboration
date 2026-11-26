/**
 * @typedef {object} RecordComment
 * @property {number} id
 * @property {string | null} field the field it is about, or null for the whole record
 * @property {string} body
 * @property {string} html the body, escaped, with mentions marked
 * @property {string} author
 * @property {boolean} mine
 * @property {string[]} mentions
 * @property {string} at an ISO 8601 time
 * @property {boolean} resolved
 * @property {string | null} resolvedBy
 */

/**
 * A record's comments, kept up to date while something shows them.
 *
 *     const comments = new Comments(room)
 *     comments.on('change', () => render(comments.list))
 *     await comments.post('Looks good, @Bob Lee', 'title')
 */
export class Comments {
    /** @type {RecordComment[]} */
    list = []
    /** @type {string[]} who may be @mentioned */
    mentionable = []
    canWrite = false
    loaded = false
    /** @type {Set<() => void>} */
    listeners = new Set()
    /** @type {ReturnType<typeof setInterval> | undefined} */
    timer

    /** @param {import('./room.js').Room} room */
    constructor(room) {
        this.room = room
    }

    /**
     * Comments on one field, or on the whole record with `null`.
     *
     * @param {string | null} field
     */
    about(field) {
        return this.list.filter((comment) => comment.field === field)
    }

    /** @param {string} _event @param {() => void} callback */
    on(_event, callback) {
        this.listeners.add(callback)

        // Asked again every few seconds while anybody listens.
        if (!this.timer) {
            this.refresh()
            this.timer = setInterval(() => this.refresh(), 10_000)
        }

        return () => {
            this.listeners.delete(callback)

            if (!this.listeners.size) this.stop()
        }
    }

    refresh() {
        return this.apply(this.room.call('comments'))
    }

    /**
     * @param {string} body mention people as `@Name`
     * @param {string | null} [field]
     */
    post(body, field = null) {
        return this.apply(this.room.call('comments.store', { body, field }))
    }

    /** @param {number} id */
    resolve(id) {
        return this.apply(this.room.call('comments.resolve', { id }))
    }

    /** @param {number} id */
    reopen(id) {
        return this.apply(this.room.call('comments.reopen', { id }))
    }

    /** @param {Promise<any>} request */
    async apply(request) {
        const answer = await request

        this.list = answer.comments ?? []
        this.mentionable = answer.mentionable ?? []
        this.canWrite = answer.canWrite === true
        this.loaded = true
        this.listeners.forEach((callback) => callback())

        return this.list
    }

    stop() {
        clearInterval(this.timer)
        this.timer = undefined
    }
}

/** @type {WeakMap<import('./room.js').Room, Comments>} one per room, however many places show them */
const known = new WeakMap()

/** @param {import('./room.js').Room} room */
export function commentsOf(room) {
    const comments = known.get(room) ?? new Comments(room)

    known.set(room, comments)

    return comments
}
